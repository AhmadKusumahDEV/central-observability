use std::time::Instant;

use opentelemetry::metrics::{Histogram, Meter};
use opentelemetry::trace::TraceContextExt;
use opentelemetry::trace::{FutureExt, SpanKind, Status, Tracer as _};
use opentelemetry::{Context, KeyValue};
use opentelemetry_sdk::trace::Tracer as SdkTracer;

pub const UNMATCHED_ROUTE: &str = "unmatched";
pub const HISTOGRAM_NAME: &str = "http.server.request.duration";

/// Batas bucket dalam detik, sama dengan template bahasa lain.
pub const DURATION_BUCKETS: [f64; 13] = [
    0.005, 0.01, 0.025, 0.05, 0.075, 0.1, 0.25, 0.5, 0.75, 1.0, 2.5, 5.0, 10.0,
];

/// Route yang dikirim ke metrics harus template, bukan URL mentah. Nilai yang
/// tidak berbentuk path dianggap `unmatched` supaya jumlah label terbatas.
pub fn normalize_route(route: Option<&str>) -> String {
    let Some(route) = route else {
        return UNMATCHED_ROUTE.to_string();
    };

    let route = route.trim();
    if route.is_empty() {
        return UNMATCHED_ROUTE.to_string();
    }

    let route = if route.contains("://") {
        route
            .split_once("://")
            .and_then(|(_, rest)| rest.find('/').map(|index| &rest[index..]))
            .unwrap_or(UNMATCHED_ROUTE)
    } else {
        route
    };

    let route = route.split(['?', '#']).next().unwrap_or(route);

    if !route.starts_with('/') {
        return UNMATCHED_ROUTE.to_string();
    }

    route.to_string()
}

pub struct HttpServerTelemetry {
    tracer: SdkTracer,
    duration: Option<Histogram<f64>>,
}

impl HttpServerTelemetry {
    pub fn new(tracer: SdkTracer, meter: &Meter) -> Self {
        let duration = meter
            .f64_histogram(HISTOGRAM_NAME)
            .with_unit("s")
            .with_description("Durasi request HTTP server")
            .with_boundaries(DURATION_BUCKETS.to_vec())
            .build();

        Self {
            tracer,
            duration: Some(duration),
        }
    }

    /// Dipakai saat `OTEL_SDK_DISABLED=true`: span tetap dibuat tetapi tidak
    /// ada histogram yang dikirim.
    pub fn disabled(tracer: SdkTracer) -> Self {
        Self {
            tracer,
            duration: None,
        }
    }

    /// Memulai span server. `parent` biasanya hasil
    /// [`crate::propagation::extract`] dari header request masuk.
    pub fn begin(&self, method: &str, route: Option<&str>, parent: Context) -> HttpExchange<'_> {
        let route = normalize_route(route);
        let span = self
            .tracer
            .span_builder(format!("{method} {route}"))
            .with_kind(SpanKind::Server)
            .with_attributes([
                KeyValue::new("http.request.method", method.to_string()),
                KeyValue::new("http.route", route.clone()),
            ])
            .start_with_context(&self.tracer, &parent);

        let context = parent.with_span(span);

        HttpExchange {
            http: self,
            context,
            started: Instant::now(),
            method: method.to_string(),
            route,
            status: 0,
        }
    }

    /// Jalur singkat: mulai span, jalankan future di dalam context itu, lalu
    /// tutup span dengan status yang dikembalikan `status_of`.
    pub async fn run<F, Fut, T, S>(
        &self,
        method: &str,
        route: Option<&str>,
        parent: Context,
        handler: F,
        status_of: S,
    ) -> T
    where
        F: FnOnce() -> Fut,
        Fut: std::future::Future<Output = T>,
        S: FnOnce(&T) -> u16,
    {
        let mut exchange = self.begin(method, route, parent);
        let context = exchange.context.clone();
        let result = handler().with_context(context.clone()).await;
        exchange.set_status(status_of(&result));
        exchange.end();

        result
    }
}

pub struct HttpExchange<'a> {
    http: &'a HttpServerTelemetry,
    context: Context,
    started: Instant,
    method: String,
    route: String,
    status: u16,
}

impl HttpExchange<'_> {
    pub fn context(&self) -> &Context {
        &self.context
    }

    pub fn route(&self) -> &str {
        &self.route
    }

    pub fn trace_id(&self) -> String {
        format!("{:032x}", self.context.span().span_context().trace_id())
    }

    pub fn set_status(&mut self, status: u16) {
        self.status = status;
    }

    pub fn record_error(&mut self, message: &str) {
        let span = self.context.span();
        span.set_status(Status::error(message.to_string()));
        span.set_attribute(KeyValue::new("error.message", message.to_string()));
    }

    pub fn end(self) {
        let status = if self.status == 0 { 200 } else { self.status };
        let seconds = self.started.elapsed().as_secs_f64();

        let span = self.context.span();

        span.set_attribute(KeyValue::new(
            "http.response.status_code",
            i64::from(status),
        ));

        if status >= 500 {
            span.set_status(Status::error(String::new()));
        }

        tracing::info!(
            http.method = %self.method,
            http.route = %self.route,
            http.status = status,
            duration_ms = seconds * 1000.0,
            "http request"
        );

        if let Some(duration) = &self.http.duration {
            duration.record(
                seconds,
                &[
                    KeyValue::new("http.request.method", self.method.clone()),
                    KeyValue::new("http.route", self.route.clone()),
                    KeyValue::new("http.response.status_code", i64::from(status)),
                ],
            );
        }

        span.end();
    }
}

#[cfg(test)]
mod tests {
    use super::normalize_route;

    #[test]
    fn keeps_trailing_route_template() {
        assert_eq!(normalize_route(Some("/users/{id}")), "/users/{id}");
    }

    #[test]
    fn strips_query_and_fragment() {
        assert_eq!(normalize_route(Some("/users?page=2#top")), "/users");
    }

    #[test]
    fn falls_back_for_unknown_values() {
        assert_eq!(normalize_route(None), "unmatched");
        assert_eq!(normalize_route(Some("")), "unmatched");
        assert_eq!(normalize_route(Some("users")), "unmatched");
    }

    #[test]
    fn extracts_path_from_absolute_url() {
        assert_eq!(
            normalize_route(Some("http://example.test/orders/42")),
            "/orders/42"
        );
    }
}
