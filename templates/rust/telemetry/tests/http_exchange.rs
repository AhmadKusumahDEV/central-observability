use std::time::Duration;

use observability_telemetry::HttpServerTelemetry;
use opentelemetry::metrics::MeterProvider as _;
use opentelemetry::trace::SpanKind;
use opentelemetry::trace::TracerProvider as _;
use opentelemetry::Context;
use opentelemetry_sdk::metrics::SdkMeterProvider;
use opentelemetry_sdk::testing::trace::new_test_exporter;
use opentelemetry_sdk::trace::SdkTracerProvider;

fn attribute(span: &opentelemetry_sdk::trace::SpanData, key: &str) -> Option<String> {
    span.attributes
        .iter()
        .find(|item| item.key.as_str() == key)
        .map(|item| item.value.to_string())
}

#[tokio::test]
async fn records_server_span_with_route_and_status() {
    let (exporter, mut spans, _shutdown) = new_test_exporter();
    let tracer_provider = SdkTracerProvider::builder()
        .with_simple_exporter(exporter)
        .build();
    let meter_provider = SdkMeterProvider::builder().build();
    let http = HttpServerTelemetry::new(
        tracer_provider.tracer("test"),
        &meter_provider.meter("test"),
    );

    let mut exchange = http.begin("GET", Some("/orders/{id}"), Context::new());
    assert_eq!(exchange.route(), "/orders/{id}");
    assert_eq!(exchange.trace_id().len(), 32);
    exchange.set_status(201);
    exchange.end();

    let span = tokio::time::timeout(Duration::from_secs(2), spans.recv())
        .await
        .expect("span harus terkirim")
        .expect("channel span tertutup");

    assert_eq!(span.name, "GET /orders/{id}");
    assert_eq!(span.span_kind, SpanKind::Server);
    assert_eq!(
        attribute(&span, "http.route").as_deref(),
        Some("/orders/{id}")
    );
    assert_eq!(
        attribute(&span, "http.request.method").as_deref(),
        Some("GET")
    );
    assert_eq!(
        attribute(&span, "http.response.status_code").as_deref(),
        Some("201")
    );
}

#[tokio::test]
async fn marks_server_error_status() {
    let (exporter, mut spans, _shutdown) = new_test_exporter();
    let tracer_provider = SdkTracerProvider::builder()
        .with_simple_exporter(exporter)
        .build();
    let meter_provider = SdkMeterProvider::builder().build();
    let http = HttpServerTelemetry::new(
        tracer_provider.tracer("test"),
        &meter_provider.meter("test"),
    );

    let mut exchange = http.begin("POST", Some("/checkout"), Context::new());
    exchange.set_status(503);
    exchange.end();

    let span = tokio::time::timeout(Duration::from_secs(2), spans.recv())
        .await
        .expect("span harus terkirim")
        .expect("channel span tertutup");

    assert_eq!(
        span.status,
        opentelemetry::trace::Status::Error {
            description: std::borrow::Cow::Borrowed("")
        }
    );
}

#[tokio::test]
async fn falls_back_to_unmatched_route() {
    let (exporter, mut spans, _shutdown) = new_test_exporter();
    let tracer_provider = SdkTracerProvider::builder()
        .with_simple_exporter(exporter)
        .build();
    let meter_provider = SdkMeterProvider::builder().build();
    let http = HttpServerTelemetry::new(
        tracer_provider.tracer("test"),
        &meter_provider.meter("test"),
    );

    let exchange = http.begin("GET", Some("/users/42?debug=1"), Context::new());
    assert_eq!(exchange.route(), "/users/42");
    exchange.end();

    let span = tokio::time::timeout(Duration::from_secs(2), spans.recv())
        .await
        .expect("span harus terkirim")
        .expect("channel span tertutup");

    assert_eq!(attribute(&span, "http.route").as_deref(), Some("/users/42"));
}
