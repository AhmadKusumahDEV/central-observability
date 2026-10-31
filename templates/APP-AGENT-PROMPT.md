# Prompt Integrasi Observability untuk Agent Aplikasi

Berikan file ini kepada agent **setelah** folder `pkg/observability` disalin ke
repo aplikasi. Isi semua nilai `<...>` terlebih dahulu. Jika ada nilai yang
belum pasti, agent harus berhenti dan bertanya; jangan menebak.

Dokumen referensi lengkap: `docs/observability/AGENT-INTEGRATION.md`. Jika
dokumen itu tidak ada di repo aplikasi, prompt ini tetap cukup untuk jalur
utama Go; untuk Gin/Echo, database, atau masalah lanjutan, minta dokumennya.

## 1. Kontrak yang harus diisi

| Item | Nilai |
|---|---|
| Bahasa | Go |
| Framework HTTP | `<net/http \| Gin \| Echo>` |
| `service.name` | `<nama-service>` |
| `deployment.environment.name` | `<dev \| staging \| prod>` |
| `service.namespace` | `<namespace>` |
| `service.version` | `<versi>` |
| Lokasi deployment | `<Docker same VPS \| native same VPS \| remote>` |
| Endpoint OTLP | `<http://otel-collector:4317 \| http://127.0.0.1:4317 \| https://otlp.madgo.my.id>` |
| Protocol | `<grpc \| http/protobuf>` |
| Path package | `pkg/observability` |

Aturan yang tidak boleh dilanggar:

- Package sudah ada. Jangan menyalin ulang package dari repo pusat, jangan
  membuat package observability baru, dan jangan mencampurnya dengan
  `go-chat/pkg/observability` atau folder `stack/`.
- Jangan menambahkan Grafana, Prometheus, Loki, Tempo, Collector, atau
  Alertmanager ke Compose aplikasi.
- Jangan membuka port `4317`, `4318`, atau port backend ke `0.0.0.0`/internet.
  Loopback `127.0.0.1` hanya untuk aplikasi native di VPS yang sama.
- Jangan menaruh password, token, atau header auth di repository.

## 2. Prasyarat — hentikan jika gagal

Jalankan dari root repo aplikasi:

```sh
go version
test -f pkg/observability/observability.go
git status --short
```

Go harus `1.26` atau lebih baru. Package memakai API Go modern dan dependency
OTel `v1.47.0`. Jika package tidak ada, berhenti; jangan menulis ulang package.

Cari entrypoint, router, dan instrumentasi lama tanpa menebak:

```sh
rg -n 'func main|ListenAndServe|gin.New|echo.New' --glob '*.go'
rg -n 'observability.Setup|SetTracerProvider|SetMeterProvider|SetLoggerProvider|otlptrace|otlpmetric|otlplog' --glob '*.go'
```

Jika sudah ada dua setup OpenTelemetry, dua middleware access log, atau package
`go-chat`, berhenti dan buat rencana migrasi. Jangan menjalankan dua pipeline.

## 3. Pilih satu cabang deployment

| Lokasi | Endpoint | Protocol | Network |
|---|---|---|---|
| Docker di VPS yang sama | `http://otel-collector:4317` | `grpc` | Join external network `monitoring` |
| Native di VPS yang sama | `http://127.0.0.1:4317` | `grpc` | Tidak perlu network Docker |
| Server remote | `https://otlp.madgo.my.id` | `http/protobuf` | Tidak perlu network `monitoring` |

Remote memakai Basic auth per aplikasi. Jangan memakai gRPC publik dan jangan
menaruh credential di URL. Jika Cloudflare Access Service Auth aktif, tambahkan
`CF-Access-Client-Id` dan `CF-Access-Client-Secret` ke header OTLP.

## 4. Buat env dan abaikan secret

Buat `otel.env.example` (bukan credential asli):

```dotenv
OTEL_SERVICE_NAME=<nama-service>
OTEL_RESOURCE_ATTRIBUTES=deployment.environment.name=<env>,service.namespace=<namespace>,service.version=<versi>
OTEL_EXPORTER_OTLP_ENDPOINT=<endpoint>
OTEL_EXPORTER_OTLP_PROTOCOL=<protocol>
OTEL_EXPORTER_OTLP_COMPRESSION=gzip
OBSERVABILITY_HEARTBEAT_INTERVAL=15
OBSERVABILITY_LOG_LEVEL=info
```

Untuk remote, tambahkan `OTEL_EXPORTER_OTLP_TIMEOUT=10000` dan
`OTEL_EXPORTER_OTLP_HEADERS` dari file secret. Jangan mencetak isi file secret
ke terminal, chat, issue, atau commit.

Tambahkan ke `.gitignore` aplikasi:

```gitignore
.env
.env.*
!.env.example
otel.env
otel.*.env
!otel.env.example
secrets/
*.htpasswd
```

`OTEL_EXPORTER_OTLP_HEADERS` memakai nilai yang sudah di-URL-encode. Karakter
`+`, `/`, dan `=` dari base64 harus menjadi `%2B`, `%2F`, dan `%3D`.

## 5. Tambahkan dependency yang dipin

Jalankan satu per satu dari root repo:

```sh
go get go.opentelemetry.io/otel@v1.47.0
go get go.opentelemetry.io/otel/sdk@v1.47.0
go get go.opentelemetry.io/otel/sdk/log@v1.47.0
go get go.opentelemetry.io/otel/sdk/metric@v1.47.0
go get go.opentelemetry.io/otel/log@v1.47.0
go get go.opentelemetry.io/otel/metric@v1.47.0
go get go.opentelemetry.io/otel/trace@v1.47.0
go get go.opentelemetry.io/otel/exporters/otlp/otlptrace/otlptracegrpc@v1.47.0
go get go.opentelemetry.io/otel/exporters/otlp/otlpmetric/otlpmetricgrpc@v1.47.0
go get go.opentelemetry.io/otel/exporters/otlp/otlplog/otlploggrpc@v0.23.0
go get go.opentelemetry.io/otel/exporters/otlp/otlptrace/otlptracehttp@v1.47.0
go get go.opentelemetry.io/otel/exporters/otlp/otlpmetric/otlpmetrichttp@v1.47.0
go get go.opentelemetry.io/otel/exporters/otlp/otlplog/otlploghttp@v0.23.0
go get go.opentelemetry.io/contrib/instrumentation/net/http/otelhttp@v0.72.0
go mod tidy
```

Tambahkan `github.com/XSAM/otelsql@v0.44.0` hanya jika memakai database SQL.
Jangan memakai `latest`. Jangan mengganti versi sebagian; core dan exporter
harus satu set yang kompatibel.

## 6. Wiring minimal

Di entrypoint, panggil `Setup` sekali sebelum server menerima request. Gunakan
route template dari mux, bukan URL mentah:

```go
stack, err := observability.Setup(ctx, observability.ConfigFromEnv())
if err != nil {
	return fmt.Errorf("setup observability: %w", err)
}

mux := http.NewServeMux()
mux.HandleFunc("GET /healthz", healthz)
mux.HandleFunc("GET /orders/{id}", getOrder)

route := func(r *http.Request) string {
	_, pattern := mux.Handler(r)
	if _, path, ok := strings.Cut(pattern, " "); ok {
		return path
	}
	return pattern
}

server := &http.Server{
	Addr:              ":8080",
	Handler:           stack.HandlerWithRoute(mux, route),
	ReadHeaderTimeout: 5 * time.Second,
}
```

Urutan shutdown: hentikan HTTP server, tutup worker/database, lalu panggil
`stack.Shutdown`. Gunakan context terpisah untuk server dan telemetry:

```go
serverCtx, cancelServer := context.WithTimeout(context.Background(), 10*time.Second)
_ = server.Shutdown(serverCtx)
cancelServer()

telemetryCtx, cancelTelemetry := context.WithTimeout(context.Background(), 10*time.Second)
_ = stack.Shutdown(telemetryCtx)
cancelTelemetry()
```

Untuk Gin: gunakan `otelgin.Middleware(service)` dan `stack.LogRequest`; untuk
Echo: gunakan `echootel.NewMiddleware(service)` dan `stack.LogRequest`. Jangan
membungkus seluruh engine Gin/Echo dengan `stack.Handler`; route akan menjadi
`unmatched`. Jika adapter framework sudah mencatat histogram, jangan panggil
`stack.RecordHTTPDuration` lagi.

HTTP client: `client := stack.HTTPClient(nil)`. Database: `otelsql.Open` dan
method `ExecContext`/`QueryContext`; jangan membungkus database dua kali.
Heartbeat sudah otomatis dari `Setup`; jangan menambahkan gauge heartbeat
manual.

## 7. Compose dan network

Untuk Docker di VPS yang sama, tambahkan:

```yaml
services:
  app:
    env_file:
      - otel.env
    networks:
      - monitoring

networks:
  monitoring:
    external: true
    name: monitoring
```

Jangan menambahkan service monitoring. Untuk aplikasi remote, jangan menambah
network `monitoring`; cukup env endpoint publik dan credential.

## 8. Verifikasi sebelum menyatakan selesai

```sh
go build ./...
go vet ./...
docker compose config --quiet
curl -fsS http://127.0.0.1:8080/healthz
curl -fsS http://127.0.0.1:8080/<route-uji>
```

Jalankan `docker compose config --quiet` hanya jika aplikasi memakai Compose.
Ganti `<route-uji>` dengan route nyata yang memicu handler dan, jika ada,
database atau HTTP client.

Tunggu 20–30 detik, lalu minta bukti dari backend. Jangan menyatakan selesai
hanya karena aplikasi start; exporter OTLP membuka koneksi secara lazy.

```promql
count by (service_name, deployment_environment_name, http_route, http_response_status_code) (
  http_server_request_duration_seconds_count{
    service_name="<service>",
    deployment_environment_name="<env>"
  }
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

Terima hanya jika: build/vet/Compose lulus; histogram muncul dengan route
template; heartbeat < 30 detik; Loki punya `trace_id`; Tempo punya satu server
span per request (bukan dua); `service.version` sama dengan kontrak; dan tidak
ada container monitoring tambahan atau secret di Git.

## 9. Berhenti dan bertanya jika

- Package hilang, berbeda dari canonical, atau ada dua setup OpenTelemetry.
- Nama service, environment, versi, endpoint, atau framework belum pasti.
- Credential belum tersedia atau harus dikirim lewat chat/repo/log.
- Perlu mengubah repo pusat, Nginx, Cloudflare, firewall, DNS, atau backend.
- Aplikasi gagal start, latency/error naik, atau exporter retry storm.
- Data belum muncul setelah dua interval export, atau auth menghasilkan
  `301/302`, `401/403`, `413`, `429`, `502`, atau `504`.
- Verifikasi membutuhkan akses yang tidak diberikan; statusnya `BLOCKED`, bukan
  “dianggap selesai”.

## 10. Format laporan

Laporkan:

```text
Status: DONE | BLOCKED | STOP
File diubah: ...
Perintah: ...
Hasil build/test: ...
Bukti metrics/logs/traces/heartbeat: ...
Trace ID uji: ...
Blocker: ...
```

Jangan melampirkan password, token, header Authorization, isi `otel.env`, PII,
atau payload sensitif.
