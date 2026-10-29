# Template Go Service

Template ini adalah contoh project aplikasi yang hanya mengirim telemetry ke
stack pusat. Di folder ini tidak ada Grafana, Prometheus, Loki, Tempo,
Collector, atau Alertmanager.

Yang perlu disalin ke project baru hanyalah folder
[`pkg/observability`](pkg/observability/observability.go). Folder itu berisi
setup OTLP untuk traces, metrics, dan logs, access log dengan `trace_id`,
histogram HTTP standar, heartbeat, dan helper HTTP client.

## Menjalankan template

```powershell
docker compose up -d --build
```

```sh
curl http://127.0.0.1:8084/work
curl http://127.0.0.1:8084/error
curl "http://127.0.0.1:8084/slow?delay_ms=900"
```

Buka Grafana di `http://127.0.0.1:3000`, lalu pilih service
`example-go-service` dan environment `dev`.

## Mengganti nama service

Ubah dua nilai di [`otel.env`](otel.env):

```dotenv
OTEL_SERVICE_NAME=nama-service-baru
OTEL_RESOURCE_ATTRIBUTES=deployment.environment.name=dev,service.namespace=examples,service.version=0.1.0
```

Ikuti aturan di [docs/NAMING.md](../../docs/NAMING.md). Untuk langkah lengkap
di project yang sudah berjalan, baca
[docs/AGENT-INTEGRATION.md](../../docs/AGENT-INTEGRATION.md).
