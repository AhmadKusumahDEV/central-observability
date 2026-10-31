use http::HeaderMap;
use opentelemetry::Context;
use opentelemetry_http::{HeaderExtractor, HeaderInjector};

/// Membaca `traceparent` dari request masuk.
pub fn extract(headers: &HeaderMap) -> Context {
    let extractor = HeaderExtractor(headers);

    opentelemetry::global::get_text_map_propagator(|propagator| propagator.extract(&extractor))
}

/// Menulis `traceparent` ke request keluar.
pub fn inject(context: &Context, headers: &mut HeaderMap) {
    let mut injector = HeaderInjector(headers);

    opentelemetry::global::get_text_map_propagator(|propagator| {
        propagator.inject_context(context, &mut injector)
    });
}
