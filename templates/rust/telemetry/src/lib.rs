//! Setup OpenTelemetry yang tidak terikat framework HTTP mana pun.
//!
//! Aplikasi hanya perlu memanggil [`Telemetry::init`], membungkus handler dengan
//! [`http::HttpServerTelemetry`], dan memakai `tracing` untuk log.

pub mod config;
pub mod http;
pub mod metrics;
pub mod propagation;
pub mod telemetry;

pub use config::{Protocol, Signal, TelemetryConfig};
pub use http::{normalize_route, HttpExchange, HttpServerTelemetry, UNMATCHED_ROUTE};
pub use metrics::Heartbeat;
pub use telemetry::{Telemetry, TelemetryError};
