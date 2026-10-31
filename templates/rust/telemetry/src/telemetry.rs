use std::error::Error;
use std::fmt;
use std::sync::Arc;

use opentelemetry::metrics::MeterProvider as _;
use opentelemetry::trace::TracerProvider as _;
use opentelemetry::KeyValue;
use opentelemetry_appender_tracing::layer::OpenTelemetryTracingBridge;
use opentelemetry_otlp::{Protocol as OtlpProtocol, WithExportConfig, WithHttpConfig};
use opentelemetry_sdk::logs::SdkLoggerProvider;
use opentelemetry_sdk::metrics::{PeriodicReader, SdkMeterProvider};
use opentelemetry_sdk::trace::SdkTracerProvider;
use opentelemetry_sdk::Resource;
use tracing_subscriber::layer::SubscriberExt;
use tracing_subscriber::util::SubscriberInitExt;
use tracing_subscriber::EnvFilter;

use crate::config::{Protocol, Signal, TelemetryConfig};
use crate::http::HttpServerTelemetry;
use crate::metrics::Heartbeat;

const INSTRUMENTATION_NAME: &str = "observability.rust";

#[derive(Debug)]
pub struct TelemetryError {
    message: String,
}

impl TelemetryError {
    fn new(message: impl Into<String>) -> Self {
        Self {
            message: message.into(),
        }
    }
}

impl fmt::Display for TelemetryError {
    fn fmt(&self, formatter: &mut fmt::Formatter<'_>) -> fmt::Result {
        write!(formatter, "{}", self.message)
    }
}

impl Error for TelemetryError {}

/// Pemilik semua provider. Simpan nilai ini selama proses hidup; saat di-drop,
/// data yang belum terkirim akan ikut hilang.
pub struct Telemetry {
    config: TelemetryConfig,
    tracer_provider: SdkTracerProvider,
    meter_provider: SdkMeterProvider,
    logger_provider: SdkLoggerProvider,
    http: Arc<HttpServerTelemetry>,
    // Callback heartbeat berhenti kalau instrument ini di-drop.
    _heartbeat: Option<Heartbeat>,
}

impl Telemetry {
    pub fn init(config: TelemetryConfig) -> Result<Self, TelemetryError> {
        let resource = build_resource(&config);

        let (tracer_provider, meter_provider, logger_provider) = if config.disabled {
            (
                SdkTracerProvider::builder()
                    .with_resource(resource.clone())
                    .build(),
                // Reader manual menjaga instrument tetap valid tanpa mengirim
                // data ke mana pun saat telemetry dimatikan.
                SdkMeterProvider::builder()
                    .with_resource(resource.clone())
                    .build(),
                SdkLoggerProvider::builder().with_resource(resource).build(),
            )
        } else {
            (
                SdkTracerProvider::builder()
                    .with_batch_exporter(build_span_exporter(&config)?)
                    .with_resource(resource.clone())
                    .build(),
                {
                    let reader = PeriodicReader::builder(build_metric_exporter(&config)?)
                        .with_interval(config.metric_interval)
                        .build();
                    SdkMeterProvider::builder()
                        .with_reader(reader)
                        .with_resource(resource.clone())
                        .build()
                },
                SdkLoggerProvider::builder()
                    .with_batch_exporter(build_log_exporter(&config)?)
                    .with_resource(resource)
                    .build(),
            )
        };

        let tracer = tracer_provider.tracer(INSTRUMENTATION_NAME);
        let meter = meter_provider.meter(INSTRUMENTATION_NAME);

        opentelemetry::global::set_tracer_provider(tracer_provider.clone());
        opentelemetry::global::set_meter_provider(meter_provider.clone());
        opentelemetry::global::set_text_map_propagator(
            opentelemetry_sdk::propagation::TraceContextPropagator::new(),
        );

        init_tracing(&config, &logger_provider);

        // Saat telemetry dimatikan, instrument tidak dibuat supaya SDK tidak
        // memperingatkan callback yang tidak akan pernah dipanggil.
        let (http, heartbeat) = if config.disabled {
            (Arc::new(HttpServerTelemetry::disabled(tracer)), None)
        } else {
            (
                Arc::new(HttpServerTelemetry::new(tracer, &meter)),
                Some(Heartbeat::register(&meter)),
            )
        };

        Ok(Self {
            config,
            tracer_provider,
            meter_provider,
            logger_provider,
            http,
            _heartbeat: heartbeat,
        })
    }

    pub fn config(&self) -> &TelemetryConfig {
        &self.config
    }

    pub fn http_server(&self) -> Arc<HttpServerTelemetry> {
        Arc::clone(&self.http)
    }

    /// Mengirim sisa span, metric, dan log tanpa mematikan provider.
    pub fn flush(&self) -> Result<(), TelemetryError> {
        let mut errors = Vec::new();

        if let Err(error) = self.tracer_provider.force_flush() {
            errors.push(format!("traces: {error}"));
        }
        if let Err(error) = self.meter_provider.force_flush() {
            errors.push(format!("metrics: {error}"));
        }
        if let Err(error) = self.logger_provider.force_flush() {
            errors.push(format!("logs: {error}"));
        }

        if errors.is_empty() {
            Ok(())
        } else {
            Err(TelemetryError::new(errors.join("; ")))
        }
    }

    pub fn shutdown(&self) -> Result<(), TelemetryError> {
        let mut errors = Vec::new();

        if let Err(error) = self.tracer_provider.shutdown() {
            errors.push(format!("traces: {error}"));
        }
        if let Err(error) = self.meter_provider.shutdown() {
            errors.push(format!("metrics: {error}"));
        }
        if let Err(error) = self.logger_provider.shutdown() {
            errors.push(format!("logs: {error}"));
        }

        if errors.is_empty() {
            Ok(())
        } else {
            Err(TelemetryError::new(errors.join("; ")))
        }
    }
}

fn build_resource(config: &TelemetryConfig) -> Resource {
    let mut attributes = vec![
        KeyValue::new("service.version", config.service_version.clone()),
        KeyValue::new("deployment.environment.name", config.environment.clone()),
        KeyValue::new("telemetry.sdk.language", "rust"),
    ];

    if let Some(namespace) = &config.service_namespace {
        attributes.push(KeyValue::new("service.namespace", namespace.clone()));
    }

    Resource::builder()
        .with_service_name(config.service_name.clone())
        .with_attributes(attributes)
        .build()
}

fn build_span_exporter(
    config: &TelemetryConfig,
) -> Result<opentelemetry_otlp::SpanExporter, TelemetryError> {
    let builder = opentelemetry_otlp::SpanExporter::builder();

    let exporter = match config.protocol {
        Protocol::HttpProtobuf => builder
            .with_http()
            .with_protocol(OtlpProtocol::HttpBinary)
            .with_endpoint(config.endpoint_for(Signal::Traces))
            .with_timeout(config.timeout)
            .with_headers(config.headers.clone())
            .build(),
        Protocol::Grpc => builder
            .with_tonic()
            .with_endpoint(config.endpoint_for(Signal::Traces))
            .with_timeout(config.timeout)
            .build(),
    };

    exporter.map_err(|error| TelemetryError::new(format!("gagal membuat span exporter: {error}")))
}

fn build_metric_exporter(
    config: &TelemetryConfig,
) -> Result<opentelemetry_otlp::MetricExporter, TelemetryError> {
    let builder = opentelemetry_otlp::MetricExporter::builder();

    let exporter = match config.protocol {
        Protocol::HttpProtobuf => builder
            .with_http()
            .with_protocol(OtlpProtocol::HttpBinary)
            .with_endpoint(config.endpoint_for(Signal::Metrics))
            .with_timeout(config.timeout)
            .with_headers(config.headers.clone())
            .build(),
        Protocol::Grpc => builder
            .with_tonic()
            .with_endpoint(config.endpoint_for(Signal::Metrics))
            .with_timeout(config.timeout)
            .build(),
    };

    exporter.map_err(|error| TelemetryError::new(format!("gagal membuat metric exporter: {error}")))
}

fn build_log_exporter(
    config: &TelemetryConfig,
) -> Result<opentelemetry_otlp::LogExporter, TelemetryError> {
    let builder = opentelemetry_otlp::LogExporter::builder();

    let exporter = match config.protocol {
        Protocol::HttpProtobuf => builder
            .with_http()
            .with_protocol(OtlpProtocol::HttpBinary)
            .with_endpoint(config.endpoint_for(Signal::Logs))
            .with_timeout(config.timeout)
            .with_headers(config.headers.clone())
            .build(),
        Protocol::Grpc => builder
            .with_tonic()
            .with_endpoint(config.endpoint_for(Signal::Logs))
            .with_timeout(config.timeout)
            .build(),
    };

    exporter.map_err(|error| TelemetryError::new(format!("gagal membuat log exporter: {error}")))
}

fn init_tracing(config: &TelemetryConfig, logger_provider: &SdkLoggerProvider) {
    let filter = EnvFilter::try_new(&config.log_level).unwrap_or_else(|_| EnvFilter::new("info"));
    let console = tracing_subscriber::fmt::layer()
        .json()
        .with_current_span(true)
        .with_span_list(false);

    let result = tracing_subscriber::registry()
        .with(filter)
        .with(console)
        .with(OpenTelemetryTracingBridge::new(logger_provider))
        .try_init();

    // Aplikasi lain mungkin sudah memasang subscriber; itu bukan error fatal.
    let _ = result;
}
