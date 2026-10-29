# AGENTS.md — Example Go Service

## Identitas telemetry

- Language: Go
- `service.name`: `example-go-service`
- `deployment.environment.name`: `dev`
- Package: `pkg/observability` (disalin dari template pusat)
- Endpoint same-host: `http://otel-collector:4317` dengan `grpc`
- Endpoint remote: `https://otlp.madgo.my.id` dengan `http/protobuf`

Ganti nama service dan environment saat template ini dipakai untuk project
nyata. Ikuti aturan di `docs/NAMING.md` pada repo pusat.

## Aturan integrasi

- Jangan menambahkan Grafana, Prometheus, Loki, Tempo, Collector, atau
  Alertmanager ke Compose aplikasi.
- Jangan menyalin folder `stack/` dari package observability lama.
- Panggil `observability.Setup` sebelum HTTP server dan `stack.Shutdown`
  setelah server berhenti.
- Gunakan route template seperti `/v1/orders/:id`, bukan URL mentah.
- Pastikan `http.server.request.duration` dan
  `app.heartbeat.timestamp` terkirim.
- Pastikan log membawa `trace_id` dan `span_id` dari context request.
- Jangan commit `otel.env`, password, token, certificate, atau file client
  credential.

## Pemeriksaan

```sh
go build ./...
docker compose config --quiet
curl -fsS http://127.0.0.1:8080/healthz
```

Verifikasi data di Grafana dengan
[docs/AGENT-INTEGRATION.md](../../docs/AGENT-INTEGRATION.md) dan
[docs/ONLINE-ACCESS.md](../../docs/ONLINE-ACCESS.md).
