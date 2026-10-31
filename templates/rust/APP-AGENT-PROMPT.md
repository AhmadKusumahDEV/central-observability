# Prompt Integrasi Rust

Gunakan setelah folder `telemetry/` dan `telemetry-tower/` disalin ke project.
Ganti nilai `<...>` sebelum memberi prompt ini ke agent.

## Kontrak

| Item | Nilai |
|---|---|
| Framework | `<axum \| actix-web \| rocket \| hyper>` |
| `service.name` | `<nama-service>` |
| Environment | `<dev \| staging \| prod>` |
| Namespace | `<namespace>` |
| Version | `<versi>` |
| Endpoint | `<http://otel-collector:4317 \| http://127.0.0.1:4317 \| https://otlp.madgo.my.id>` |
| Protocol | `<grpc \| http/protobuf>` |

Jangan menambahkan Grafana, Prometheus, Loki, Tempo, Collector, atau
Alertmanager. Jangan menaruh credential di repository, URL, log, atau chat.

## Prasyarat

```sh
rustc --version
cargo --version
cargo fmt --all --check
cargo test --workspace
```

Butuh Rust 1.88 atau lebih baru karena `opentelemetry-otlp` 0.33 memakai
`tonic` 0.14. Jangan memakai `latest`; versi dependency sudah di-pin di
`Cargo.toml` dan `Cargo.lock` ikut di-commit.

## Langkah

1. Salin `telemetry/` dan, bila memakai tower, `telemetry-tower/` ke project.
   Daftarkan keduanya sebagai member workspace.
2. Buat `otel.env` dari `otel.env.example`. Isi service, environment, versi,
   endpoint, protocol, dan header remote. Jangan commit file itu.
3. Panggil `Telemetry::init(TelemetryConfig::from_env())` sekali saat startup;
   simpan hasilnya di `Arc` selama proses hidup.
4. Pasang `ObservabilityLayer` di lapisan yang sudah punya pola route.
   - axum: `route_layer`, lalu ambil `MatchedPath` dari extension.
   - actix-web: panggil `begin` dengan `req.match_pattern()`.
   - rocket: ambil `request.route()` di handler, bukan di fairing.
   - hyper: isi extension route sendiri dari router.
5. Kirim label route template, misalnya `/orders/{id}`, bukan URL mentah.
6. Pastikan histogram `http.server.request.duration` memakai unit `s` dan
   atribut method, route, status. Template sudah melakukannya.
7. Jangan menambahkan layer tracing lain yang membuat span server kedua.
8. Saat shutdown: hentikan server, lalu `telemetry.flush()` kemudian
   `telemetry.shutdown()`.

## Verifikasi

```sh
cargo test --workspace
curl -fsS http://127.0.0.1:8080/healthz
curl -fsS http://127.0.0.1:8080/<route-uji>
```

Tunggu 20-30 detik, lalu cek di Grafana:

```promql
sum by (service_name, deployment_environment_name, http_route) (
  rate(http_server_request_duration_seconds_count{
    service_name="<service>", deployment_environment_name="<env>"
  }[5m])
)
```

```promql
time() - max_over_time(app_heartbeat_timestamp_seconds{
  service_name="<service>", deployment_environment_name="<env>"
}[2m])
```

```logql
{service_name="<service>", deployment_environment_name="<env>"} | trace_id=~".+"
```

```traceql
{ resource.service.name = "<service>" &&
  resource.deployment.environment.name = "<env>" }
```

Terima hanya jika histogram muncul, heartbeat di bawah 30 detik, log punya
trace ID, dan Tempo punya satu server span per request. Jika belum muncul
setelah dua interval export, laporkan `BLOCKED` beserta log aplikasi, bukan
`DONE`.
