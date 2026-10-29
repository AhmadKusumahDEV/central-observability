# Catatan Verifikasi Integrasi

Catatan ini merekam uji yang dijalankan pada 29 Oktober 2026 terhadap stack
pusat yang sedang berjalan. Uji memakai template
[templates/go-service](../templates/go-service/), bukan aplikasi go-chat.
Folder `go-chat` hanya dibaca untuk membandingkan package lama; tidak ada file
di sana yang diubah.

## Build dan pemeriksaan statis

```sh
cd templates/go-service
gofmt -w ./cmd ./pkg
go build ./...
go vet ./...
```

Hasil: `go build` dan `go vet` selesai tanpa error.

## Uji lewat Docker Compose client

```sh
docker compose -f templates/go-service/docker-compose.yml up -d --build
docker compose -f templates/go-service/docker-compose.yml ps
curl -fsS http://127.0.0.1:8084/work
curl -sS http://127.0.0.1:8084/error
curl -fsS 'http://127.0.0.1:8084/slow?delay_ms=900'
```

Hasil:

- Image `central-observability-example-go-service:0.1.0` berhasil dibangun.
- Container `example-go-service-app-1` berstatus `Up (healthy)`.
- Port host `127.0.0.1:8084` terhubung ke aplikasi di port container `8080`.
- Aplikasi dapat menjangkau Collector melalui `otel-collector:4317` pada
  external network `monitoring`.

Trace dari request `/work` pada uji Docker:

```text
a3199ca5108e0e2219484756ccb0f7c2
```

Trace tersebut berisi span berikut di Tempo:

| Span | Jenis | Bukti |
|---|---|---|
| `GET /work` | HTTP server | `http.route=/work`, status `200` |
| `work.process` | Internal | span anak request |
| `sql.conn.exec` | Database client | `INSERT INTO work_items ...` |
| `HTTP GET` | HTTP client | request ke `/downstream`, status `200` |

## Prometheus

Query yang dipakai:

```promql
count by (service_name, deployment_environment_name, http_route, http_response_status_code) (
  http_server_request_duration_seconds_count{
    service_name="example-go-service",
    http_route=~"/work|/error|/slow"
  }
)
```

Hasil: tiga series ditemukan.

| Route | Status | Service | Environment |
|---|---|---|---|
| `/work` | `200` | `example-go-service` | `dev` |
| `/error` | `500` | `example-go-service` | `dev` |
| `/slow` | `200` | `example-go-service` | `dev` |

Heartbeat juga ditemukan:

```promql
last_over_time(app_heartbeat_timestamp_seconds{
  service_name="example-go-service"
}[2m])
```

Label hasil query menyertakan `service_name=example-go-service`,
`service_namespace=examples`, `service_version=0.1.0`, dan
`deployment_environment_name=dev`.

## Loki

Query log untuk trace `/work`:

```logql
{service_name="example-go-service"} | trace_id="a3199ca5108e0e2219484756ccb0f7c2"
```

Hasil: dua baris log ditemukan, yaitu `http request` dan `work completed`.
Keduanya memiliki `trace_id`, `span_id`, `service_name`,
`deployment_environment_name`, dan `service_version`. Log access juga memiliki
`http_route` dan `http_status`.

## Tempo dan Grafana

Trace di atas dapat dibuka dari Tempo dan memiliki span HTTP server, HTTP
client, serta database. Query Grafana melalui datasource yang sudah
di-provision juga berhasil:

| Datasource | Hasil |
|---|---|
| Prometheus | 7 series untuk `service_name=example-go-service` |
| Loki | 2 stream untuk trace yang dicari |
| Tempo | 2 trace terbaru untuk `service.name=example-go-service` |

Ini membuktikan Grafana dapat membaca ketiga backend dengan datasource UID
`prometheus-main`, `loki-main`, dan `tempo-main`.

## Smoke test dan alert

```sh
make test-rules
```

Hasil: `SUCCESS` dari `promtool v3.15.1`.

```sh
sh scripts/smoke-test.sh
```

Hasil:

```text
PASS: metrics di Prometheus
PASS: logs di Loki
PASS: traces di Tempo
Smoke test selesai: metrics, logs, dan traces dari service 'smoke-test' diterima stack pusat.
```

## Yang belum diuji

- Pengiriman notifikasi Telegram, Slack, atau email secara nyata. Rule dan
  routing sudah dimuat, tetapi pengiriman ke channel eksternal tidak
  dijalankan dalam uji ini.
- Klik manual tombol `TraceID` di UI Grafana. Datasource Loki dan Tempo sudah
  diuji lewat API Grafana, dan field `trace_id` sudah ada di log.
- Retensi jangka panjang, kapasitas disk produksi, dan restore backup.
- Aplikasi go-chat. Uji ini hanya memakai template pusat; go-chat tidak diubah.

Kembali ke [AGENT-INTEGRATION.md](AGENT-INTEGRATION.md) atau
[README.md](README.md).
