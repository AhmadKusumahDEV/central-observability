use std::collections::HashMap;
use std::env;
use std::time::Duration;

/// Sinyal OTLP yang punya path sendiri di endpoint HTTP.
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum Signal {
    Traces,
    Metrics,
    Logs,
}

impl Signal {
    pub fn path(self) -> &'static str {
        match self {
            Signal::Traces => "/v1/traces",
            Signal::Metrics => "/v1/metrics",
            Signal::Logs => "/v1/logs",
        }
    }

    fn env_key(self) -> &'static str {
        match self {
            Signal::Traces => "OTEL_EXPORTER_OTLP_TRACES_ENDPOINT",
            Signal::Metrics => "OTEL_EXPORTER_OTLP_METRICS_ENDPOINT",
            Signal::Logs => "OTEL_EXPORTER_OTLP_LOGS_ENDPOINT",
        }
    }
}

#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum Protocol {
    Grpc,
    HttpProtobuf,
}

/// Semua nilai punya default yang aman supaya aplikasi tetap hidup walau
/// environment variable belum lengkap.
#[derive(Debug, Clone)]
pub struct TelemetryConfig {
    pub service_name: String,
    pub environment: String,
    pub service_version: String,
    pub service_namespace: Option<String>,
    pub endpoint: String,
    pub protocol: Protocol,
    pub headers: HashMap<String, String>,
    pub timeout: Duration,
    pub metric_interval: Duration,
    pub log_level: String,
    pub disabled: bool,
    signal_endpoints: HashMap<&'static str, String>,
}

impl TelemetryConfig {
    pub fn from_env() -> Self {
        Self::from_pairs(env::vars())
    }

    pub fn from_pairs<I, K, V>(pairs: I) -> Self
    where
        I: IntoIterator<Item = (K, V)>,
        K: Into<String>,
        V: Into<String>,
    {
        let env: HashMap<String, String> = pairs
            .into_iter()
            .map(|(key, value)| (key.into(), value.into()))
            .collect();

        let resource = parse_key_value_list(env.get("OTEL_RESOURCE_ATTRIBUTES"));
        let service_name = non_empty(env.get("OTEL_SERVICE_NAME"))
            .or_else(|| resource.get("service.name").cloned())
            .unwrap_or_else(|| "unknown-service".to_string());

        let protocol = match env
            .get("OTEL_EXPORTER_OTLP_PROTOCOL")
            .map(|value| value.trim().to_ascii_lowercase())
            .unwrap_or_default()
            .as_str()
        {
            "grpc" => Protocol::Grpc,
            _ => Protocol::HttpProtobuf,
        };

        let default_endpoint = match protocol {
            Protocol::Grpc => "http://otel-collector:4317",
            Protocol::HttpProtobuf => "http://otel-collector:4318",
        };

        let endpoint = non_empty(env.get("OTEL_EXPORTER_OTLP_ENDPOINT"))
            .unwrap_or_else(|| default_endpoint.to_string());

        let mut signal_endpoints = HashMap::new();
        for signal in [Signal::Traces, Signal::Metrics, Signal::Logs] {
            if let Some(value) = non_empty(env.get(signal.env_key())) {
                signal_endpoints.insert(signal.env_key(), value);
            }
        }

        let timeout_ms = parse_u64(env.get("OTEL_EXPORTER_OTLP_TIMEOUT"))
            .unwrap_or(10_000)
            .clamp(1_000, 60_000);
        let interval_ms = parse_u64(env.get("OTEL_METRIC_EXPORT_INTERVAL"))
            .unwrap_or(10_000)
            .clamp(1_000, 300_000);

        TelemetryConfig {
            service_name,
            environment: resource
                .get("deployment.environment.name")
                .cloned()
                .unwrap_or_else(|| "unknown".to_string()),
            service_version: resource
                .get("service.version")
                .cloned()
                .unwrap_or_else(|| "unknown".to_string()),
            service_namespace: resource.get("service.namespace").cloned(),
            endpoint,
            protocol,
            headers: parse_key_value_list(env.get("OTEL_EXPORTER_OTLP_HEADERS")),
            timeout: Duration::from_millis(timeout_ms),
            metric_interval: Duration::from_millis(interval_ms),
            log_level: non_empty(env.get("OTEL_LOG_LEVEL")).unwrap_or_else(|| "info".to_string()),
            disabled: parse_bool(env.get("OTEL_SDK_DISABLED")),
            signal_endpoints,
        }
    }

    /// Endpoint lengkap untuk satu sinyal. OTLP/HTTP butuh path /v1/...,
    /// sedangkan gRPC memakai host:port yang sama untuk semua sinyal.
    pub fn endpoint_for(&self, signal: Signal) -> String {
        if let Some(override_endpoint) = self.signal_endpoints.get(signal.env_key()) {
            return override_endpoint.clone();
        }

        match self.protocol {
            Protocol::Grpc => strip_scheme(&self.endpoint),
            Protocol::HttpProtobuf => {
                format!("{}{}", self.endpoint.trim_end_matches('/'), signal.path())
            }
        }
    }

    pub fn header_pairs(&self) -> Vec<(String, String)> {
        self.headers
            .iter()
            .map(|(key, value)| (key.clone(), value.clone()))
            .collect()
    }
}

fn strip_scheme(endpoint: &str) -> String {
    let without_scheme = endpoint
        .strip_prefix("http://")
        .or_else(|| endpoint.strip_prefix("https://"))
        .unwrap_or(endpoint);

    without_scheme
        .split('/')
        .next()
        .unwrap_or(without_scheme)
        .to_string()
}

fn parse_key_value_list(value: Option<&String>) -> HashMap<String, String> {
    let mut parsed = HashMap::new();

    if let Some(value) = value {
        for pair in value.split(',') {
            let pair = pair.trim();
            if pair.is_empty() {
                continue;
            }

            if let Some((key, item)) = pair.split_once('=') {
                let key = key.trim();
                if !key.is_empty() {
                    parsed.insert(key.to_string(), item.trim().to_string());
                }
            }
        }
    }

    parsed
}

fn non_empty(value: Option<&String>) -> Option<String> {
    value
        .map(|item| item.trim().to_string())
        .filter(|item| !item.is_empty())
}

fn parse_u64(value: Option<&String>) -> Option<u64> {
    value.and_then(|item| item.trim().parse::<u64>().ok())
}

fn parse_bool(value: Option<&String>) -> bool {
    matches!(
        value.map(|item| item.trim().to_ascii_lowercase()),
        Some(item) if item == "1" || item == "true" || item == "yes" || item == "on"
    )
}

#[cfg(test)]
mod tests {
    use super::*;

    fn config(pairs: &[(&str, &str)]) -> TelemetryConfig {
        TelemetryConfig::from_pairs(pairs.iter().map(|(k, v)| (*k, *v)))
    }

    #[test]
    fn appends_signal_path_for_http() {
        let config = config(&[
            ("OTEL_EXPORTER_OTLP_ENDPOINT", "http://localhost:4318/"),
            ("OTEL_EXPORTER_OTLP_PROTOCOL", "http/protobuf"),
        ]);

        assert_eq!(
            config.endpoint_for(Signal::Traces),
            "http://localhost:4318/v1/traces"
        );
        assert_eq!(
            config.endpoint_for(Signal::Metrics),
            "http://localhost:4318/v1/metrics"
        );
        assert_eq!(
            config.endpoint_for(Signal::Logs),
            "http://localhost:4318/v1/logs"
        );
    }

    #[test]
    fn grpc_endpoint_has_no_scheme_or_path() {
        let config = config(&[
            ("OTEL_EXPORTER_OTLP_ENDPOINT", "http://otel-collector:4317"),
            ("OTEL_EXPORTER_OTLP_PROTOCOL", "grpc"),
        ]);

        assert_eq!(config.endpoint_for(Signal::Traces), "otel-collector:4317");
    }

    #[test]
    fn resource_attributes_supply_environment() {
        let config = config(&[
            ("OTEL_SERVICE_NAME", "chat-api"),
            (
                "OTEL_RESOURCE_ATTRIBUTES",
                "deployment.environment.name=prod,service.version=1.2.3,service.namespace=chat",
            ),
        ]);

        assert_eq!(config.service_name, "chat-api");
        assert_eq!(config.environment, "prod");
        assert_eq!(config.service_version, "1.2.3");
        assert_eq!(config.service_namespace.as_deref(), Some("chat"));
    }

    #[test]
    fn headers_are_parsed() {
        let config = config(&[(
            "OTEL_EXPORTER_OTLP_HEADERS",
            "Authorization=Basic abc,x-tenant=acme",
        )]);

        assert_eq!(
            config.headers.get("Authorization"),
            Some(&"Basic abc".to_string())
        );
        assert_eq!(config.headers.get("x-tenant"), Some(&"acme".to_string()));
    }
}
