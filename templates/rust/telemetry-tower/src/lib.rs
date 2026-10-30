//! Layer tower generik. Layer ini tidak mengimpor axum, actix, atau hyper,
//! sehingga bisa dipakai framework apa pun yang memakai trait `tower::Service`.

use std::future::Future;
use std::pin::Pin;
use std::sync::Arc;
use std::task::{Context as TaskContext, Poll};

use http::{Extensions, Method, Request, Uri};
use observability_telemetry::http::HttpServerTelemetry;
use observability_telemetry::propagation;
use opentelemetry::trace::FutureExt;
use tower::{Layer, Service, ServiceExt};

/// Menentukan label route dari request. Nilai harus template, misalnya
/// `/users/{id}`, bukan URL mentah.
pub type RouteFn = Arc<dyn Fn(&Extensions, &Method, &Uri) -> Option<String> + Send + Sync>;

#[derive(Clone)]
pub struct ObservabilityLayer {
    http: Arc<HttpServerTelemetry>,
    route: RouteFn,
}

impl ObservabilityLayer {
    pub fn new(http: Arc<HttpServerTelemetry>) -> Self {
        Self {
            http,
            route: Arc::new(|_, _, _| None),
        }
    }

    /// Tanpa resolver, label route menjadi `unmatched`. Pasang resolver dari
    /// framework, misalnya `MatchedPath` pada axum.
    pub fn with_route<F>(mut self, route: F) -> Self
    where
        F: Fn(&Extensions, &Method, &Uri) -> Option<String> + Send + Sync + 'static,
    {
        self.route = Arc::new(route);
        self
    }
}

impl<S> Layer<S> for ObservabilityLayer {
    type Service = ObservabilityService<S>;

    fn layer(&self, inner: S) -> Self::Service {
        ObservabilityService {
            inner,
            http: Arc::clone(&self.http),
            route: Arc::clone(&self.route),
        }
    }
}

#[derive(Clone)]
pub struct ObservabilityService<S> {
    inner: S,
    http: Arc<HttpServerTelemetry>,
    route: RouteFn,
}

impl<S, B, ResponseBody> Service<Request<B>> for ObservabilityService<S>
where
    S: Service<Request<B>, Response = http::Response<ResponseBody>> + Clone + Send + 'static,
    S::Future: Send + 'static,
    S::Error: Send + 'static,
    B: Send + 'static,
    ResponseBody: Send + 'static,
{
    type Response = http::Response<ResponseBody>;
    type Error = S::Error;
    type Future = Pin<Box<dyn Future<Output = Result<Self::Response, Self::Error>> + Send>>;

    fn poll_ready(&mut self, context: &mut TaskContext<'_>) -> Poll<Result<(), Self::Error>> {
        self.inner.poll_ready(context)
    }

    fn call(&mut self, request: Request<B>) -> Self::Future {
        let http = Arc::clone(&self.http);
        let route_fn = Arc::clone(&self.route);
        let clone = self.inner.clone();
        let inner = std::mem::replace(&mut self.inner, clone);

        Box::pin(async move {
            let route = route_fn(request.extensions(), request.method(), request.uri());
            let parent = propagation::extract(request.headers());
            let mut exchange = http.begin(request.method().as_str(), route.as_deref(), parent);
            let context = exchange.context().clone();

            let result = inner.oneshot(request).with_context(context.clone()).await;

            match &result {
                Ok(response) => exchange.set_status(response.status().as_u16()),
                Err(_) => {
                    exchange.set_status(500);
                    exchange.record_error("request failed");
                }
            }

            exchange.end();

            result
        })
    }
}
