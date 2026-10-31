use std::convert::Infallible;
use std::task::{Context as TaskContext, Poll};

use http::{Request, Response};
use observability_telemetry::HttpServerTelemetry;
use observability_tower::ObservabilityLayer;
use opentelemetry::metrics::MeterProvider as _;
use opentelemetry::trace::TracerProvider as _;
use opentelemetry_sdk::metrics::SdkMeterProvider;
use opentelemetry_sdk::testing::trace::new_test_exporter;
use opentelemetry_sdk::trace::SdkTracerProvider;
use tower::{Layer, Service};

#[derive(Clone)]
struct Echo;

impl Service<Request<()>> for Echo {
    type Response = Response<()>;
    type Error = Infallible;
    type Future = std::future::Ready<Result<Self::Response, Self::Error>>;

    fn poll_ready(&mut self, _context: &mut TaskContext<'_>) -> Poll<Result<(), Self::Error>> {
        Poll::Ready(Ok(()))
    }

    fn call(&mut self, _request: Request<()>) -> Self::Future {
        std::future::ready(Ok(Response::builder().status(204).body(()).unwrap()))
    }
}

#[tokio::test]
async fn layer_records_route_from_resolver() {
    let (exporter, mut spans, _shutdown) = new_test_exporter();
    let tracer_provider = SdkTracerProvider::builder()
        .with_simple_exporter(exporter)
        .build();
    let meter_provider = SdkMeterProvider::builder().build();
    let http = std::sync::Arc::new(HttpServerTelemetry::new(
        tracer_provider.tracer("test"),
        &meter_provider.meter("test"),
    ));

    let layer = ObservabilityLayer::new(http).with_route(|_extensions, _method, uri| {
        // Di aplikasi nyata, ambil pola route dari framework.
        Some(if uri.path().starts_with("/orders/") {
            "/orders/{id}".to_string()
        } else {
            uri.path().to_string()
        })
    });

    let mut service = layer.layer(Echo);
    let response = service
        .call(Request::builder().uri("/orders/42").body(()).unwrap())
        .await
        .expect("service harus berhasil");

    assert_eq!(response.status().as_u16(), 204);

    let span = tokio::time::timeout(std::time::Duration::from_secs(2), spans.recv())
        .await
        .expect("span harus terkirim")
        .expect("channel span tertutup");

    assert_eq!(span.name, "GET /orders/{id}");
}
