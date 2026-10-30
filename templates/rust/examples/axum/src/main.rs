use std::sync::Arc;
use std::time::Duration;

use axum::extract::MatchedPath;
use axum::http::StatusCode;
use axum::routing::get;
use axum::Router;
use observability_telemetry::{Telemetry, TelemetryConfig};
use observability_tower::ObservabilityLayer;

#[tokio::main]
async fn main() -> Result<(), Box<dyn std::error::Error>> {
    let telemetry = Arc::new(Telemetry::init(TelemetryConfig::from_env())?);

    // route_layer berjalan setelah routing, jadi MatchedPath sudah terisi.
    let observability =
        ObservabilityLayer::new(telemetry.http_server()).with_route(|extensions, _method, _uri| {
            extensions
                .get::<MatchedPath>()
                .map(|path| path.as_str().to_string())
        });

    let app = Router::new()
        .route("/healthz", get(healthz))
        .route("/work", get(work))
        .route("/slow", get(slow))
        .route("/error", get(fail))
        .route_layer(observability);

    let listener = tokio::net::TcpListener::bind("0.0.0.0:8080").await?;
    tracing::info!(port = 8080, "server listening");

    axum::serve(listener, app)
        .with_graceful_shutdown(shutdown_signal())
        .await?;

    let _ = telemetry.flush();
    let _ = telemetry.shutdown();

    Ok(())
}

async fn healthz() -> &'static str {
    r#"{"status":"ok"}"#
}

async fn work() -> &'static str {
    tracing::info!(job = "report", items = 42, "menjalankan pekerjaan contoh");

    r#"{"work":"done","items":42}"#
}

async fn slow() -> &'static str {
    tokio::time::sleep(Duration::from_millis(350)).await;
    tracing::warn!(delay_ms = 350, "request lambat pada contoh");

    r#"{"slow":true}"#
}

async fn fail() -> (StatusCode, &'static str) {
    tracing::error!(reason = "contoh kegagalan", "request gagal");

    (
        StatusCode::INTERNAL_SERVER_ERROR,
        r#"{"error":"internal error"}"#,
    )
}

async fn shutdown_signal() {
    let _ = tokio::signal::ctrl_c().await;
}
