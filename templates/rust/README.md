# Template Observability Rust

Template ini mengirim traces, metrics (termasuk histogram), dan logs ke
Collector pusat. Isinya workspace kecil dengan dua crate:

- `telemetry/` berisi setup OTLP, span server, histogram, dan heartbeat. Tidak
  mengimpor axum, actix, atau framework apa pun.
- `telemetry-tower/` berisi layer generik untuk framework yang memakai
  `tower::Service`.

Contoh `examples/axum/` hanya untuk mencoba template. Jangan menambahkan
Grafana, Prometheus, Loki, Tempo, Collector, atau Alertmanager ke Compose
aplikasi.

## Yang disalin ke project

```text
telemetry/
telemetry-tower/          # hanya kalau memakai tower (axum, hyper)
otel.env.example
```

Jangan salin `examples/`. Ganti nama package kalau perlu, lalu daftarkan kedua
crate di workspace aplikasi:

```toml
[workspace]
members = ["app", "telemetry", "telemetry-tower"]
```

## Menjalankan contoh

```sh
cargo run -p observability-example-axum
```

Lalu panggil:

```sh
curl -s http://127.0.0.1:8080/healthz
curl -s http://127.0.0.1:8080/work
curl -s http://127.0.0.1:8080/slow
curl -s -o /dev/null -w '%{http_code}' http://127.0.0.1:8080/error
```

## Inisialisasi

```rust
use std::sync::Arc;

use observability_telemetry::{Telemetry, TelemetryConfig};
use observability_tower::ObservabilityLayer;

let telemetry = Arc::new(Telemetry::init(TelemetryConfig::from_env())?);

let observability = ObservabilityLayer::new(telemetry.http_server()).with_route(
    |extensions, _method, _uri| extensions.get::<RoutePattern>().cloned(),
);
```

Pasang layer di tempat yang sudah punya pola route. Di axum pakai
`route_layer`, bukan `layer`, karena `MatchedPath` baru terisi setelah routing.
Tanpa resolver, label route menjadi `unmatched`.

## Framework lain

| Framework | Cara mengambil pola route |
|---|---|
| axum | `route_layer` lalu `extensions.get::<MatchedPath>()` |
| actix-web | `req.match_pattern()` |
| rocket | `request.route().map(\|route\| route.uri.to_string())` di dalam handler, bukan fairing `on_request` |
| hyper / tower murni | siapkan extension sendiri dari router Anda |

Layer `tower` bisa dipakai apa adanya untuk axum dan hyper. Untuk actix dan
rocket, panggil `HttpServerTelemetry::begin` langsung di dalam handler:

```rust
let mut exchange = telemetry.http_server().begin("GET", Some("/orders/{id}"), Context::new());
exchange.set_status(200);
exchange.end();
```

## Endpoint

| Lokasi | Endpoint | Protocol |
|---|---|---|
| Docker di VPS yang sama | `http://otel-collector:4317` | `grpc` |
| Native di VPS yang sama | `http://127.0.0.1:4317` | `grpc` |
| Server remote | `https://otlp.madgo.my.id` | `http/protobuf` |

Kalau `grpc-tonic` dan `http-proto` sama-sama aktif, template memilih transport
secara eksplisit dari `OTEL_EXPORTER_OTLP_PROTOCOL`. Endpoint untuk OTLP/HTTP
ditambahkan path `/v1/traces`, `/v1/metrics`, dan `/v1/logs`; endpoint gRPC
memakai `host:port` tanpa skema. Jangan memakai URL `/v1/traces` sebagai
`OTEL_EXPORTER_OTLP_ENDPOINT`.

## Log dan trace

Log memakai `tracing`. Bridge `opentelemetry-appender-tracing` mengirim event
ke OTLP, dan `trace_id` ikut selama event berada di dalam context span server:

```rust
tracing::info!(order_id = 7, "membaca order");
```

Untuk memastikan event ikut context, jalankan handler di dalam
`exchange.context()` (layer tower sudah melakukannya).

## Heartbeat

Gauge `app.heartbeat.timestamp` didaftarkan sebagai observable gauge, jadi
nilainya dibaca setiap kali interval export metrics berjalan. Tidak perlu task
latar belakang dan tidak perlu memanggil fungsi apa pun dari handler.

## Verifikasi yang sudah dijalankan

- `cargo fmt --all --check` lolos.
- `cargo test --workspace` lolos: 8 unit test (config, route) dan 4 test
  integrasi (span server, status 5xx, fallback route, layer tower).
- `cargo run -p observability-example-axum` dengan `OTEL_SDK_DISABLED=true`
  berjalan dan mendengarkan port 8080 tanpa peringatan SDK.

Pengiriman HTTP sungguhan ke Collector belum diuji dari mesin ini karena
socket loopback diblokir lingkungan kerja. Uji di server dengan langkah di
[../../docs/INTEGRATION.md](../../docs/INTEGRATION.md).

Lihat [APP-AGENT-PROMPT.md](APP-AGENT-PROMPT.md) untuk instruksi handoff ke
agent di project aplikasi.
