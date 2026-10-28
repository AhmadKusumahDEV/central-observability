# Integrasi Aplikasi

Dokumen ini adalah kontrak antara aplikasi dan stack observability pusat.
Aplikasi hanya perlu mengirim OTLP ke Collector. Jangan menambahkan Grafana,
Prometheus, Loki, Tempo, atau Collector ke `docker-compose.yml` aplikasi.

## 1. Pilih endpoint

### Aplikasi di host yang sama

Aplikasi Docker harus bergabung ke external network `monitoring`:

```yaml
services:
  chat-api:
    build: .
    environment:
      OTEL_EXPORTER_OTLP_ENDPOINT: http://otel-collector:4317
      OTEL_EXPORTER_OTLP_PROTOCOL: grpc
      OTEL_SERVICE_NAME: chat-api
      OTEL_RESOURCE_ATTRIBUTES: deployment.environment.name=prod,service.namespace=acme,service.version=1.4.2
      OTEL_METRICS_EXPORTER: otlp
      OTEL_TRACES_EXPORTER: otlp
      OTEL_LOGS_EXPORTER: otlp
    networks:
      - monitoring

networks:
  monitoring:
    external: true
    name: monitoring
```

Itu saja untuk Docker Compose aplikasi. Tidak ada service monitoring lokal.

Jika aplikasi berjalan langsung di host, gunakan
`http://127.0.0.1:4317`. Jika memakai OTLP/HTTP, gunakan base URL
`http://otel-collector:4318` dan
`OTEL_EXPORTER_OTLP_PROTOCOL=http/protobuf`.

### Aplikasi di server remote

Pilihan aman pertama adalah WireGuard atau Tailscale. Lewat VPN, gunakan
endpoint privat Collector seperti pada host yang sama.

Jika memakai profil Caddy publik:

```dotenv
OTEL_EXPORTER_OTLP_ENDPOINT=https://otel.example.com:443
OTEL_EXPORTER_OTLP_PROTOCOL=grpc
OTEL_EXPORTER_OTLP_HEADERS=authorization=Basic%20BASE64_URLENCODED_CREDENTIALS
OTEL_SERVICE_NAME=chat-api
OTEL_RESOURCE_ATTRIBUTES=deployment.environment.name=prod,service.namespace=acme,service.version=1.4.2
```

Buat nilai Basic auth:

```sh
credential=$(printf '%s' 'otel-writer:password-anda' | base64 | tr -d '\n')
encoded=$(printf '%s' "$credential" |
  sed -e 's/+/%2B/g' -e 's/\//%2F/g' -e 's/=/%3D/g')
printf 'authorization=Basic%%20%s\n' "$encoded"
```

Nilai header di environment OTel di-URL-decode oleh SDK. Karena itu `+`,
`/`, dan `=` pada hasil base64 harus diubah menjadi
`%2B`, `%2F`, dan `%3D`. Jika Basic auth diatur
langsung di kode dengan opsi `WithHeaders`, kirim nilai base64 tanpa
percent-encoding.

Untuk OTLP/HTTP remote, gunakan
`OTEL_EXPORTER_OTLP_ENDPOINT=https://otel.example.com` dan
`OTEL_EXPORTER_OTLP_PROTOCOL=http/protobuf`. Jangan menambahkan
`/v1/traces` ke base endpoint umum.

## 2. Variabel wajib

| Variabel | Nilai |
|---|---|
| `OTEL_EXPORTER_OTLP_ENDPOINT` | Endpoint Collector lokal atau HTTPS remote |
| `OTEL_EXPORTER_OTLP_PROTOCOL` | `grpc` atau `http/protobuf` |
| `OTEL_SERVICE_NAME` | Nama service yang stabil, contoh `chat-api` |
| `OTEL_RESOURCE_ATTRIBUTES` | Minimal `deployment.environment.name=prod` |
| `OTEL_TRACES_EXPORTER` | `otlp` |
| `OTEL_METRICS_EXPORTER` | `otlp` |
| `OTEL_LOGS_EXPORTER` | `otlp` |

Gunakan nilai environment yang konsisten: `dev`, `staging`, atau
`prod`. Nama resource yang dipakai seluruh stack adalah
`deployment.environment.name`. Jangan memakai
`deployment.environment` untuk data baru karena label itu tidak muncul di
dashboard dan query alert standar.

Atribut opsional yang berguna:

```text
service.namespace=acme
service.version=1.4.2
service.instance.id=chat-api-1
```

`service.name` dan `deployment.environment.name` harus stabil. Nilai acak atau
memasukkan request ID ke resource akan membuat series baru terus-menerus.

## 3. Kontrak metrics

Gunakan instrument OTel, bukan nama metric khusus stack ini:

```text
http.server.request.duration
unit: s
type: histogram, explicit buckets
```

Atribut datapoint wajib:

```text
http.request.method   GET/POST/...
http.route            /v1/orders/:id, bukan URL mentah
http.response.status_code  200/500/...
```

Prometheus pusat mempromosikan resource attribute `service.name`,
`service.namespace`, `service.version`, dan
`deployment.environment.name` ke label metric. Query dashboard memakai
`service_name` dan `deployment_environment_name` secara langsung,
sehingga filter environment tidak bergantung pada UI atau join manual.

Explicit bucket minimal yang disarankan untuk API:

```text
0.005, 0.01, 0.025, 0.05, 0.075, 0.1, 0.25, 0.5, 0.75, 1, 2.5, 5, 10
```

Request rate dihitung dari `http_server_request_duration_seconds_count`;
5xx rate memakai `http_response_status_code`; p50/p95/p99 dihitung dari
`http_server_request_duration_seconds_bucket`.

Heartbeat untuk deteksi service mati:

```text
app.heartbeat.timestamp
unit: s
gauge value: Unix timestamp saat ini
interval: 10-15 detik
```

Collector memberikan fallback `service.name=unknown-service` dan
`deployment.environment.name=unknown` bila aplikasi lupa mengirim resource.
Data dengan nilai fallback harus dianggap sebagai kesalahan onboarding.

## 4. Kontrak logs dan korelasi

Kirim logs sebagai OTLP LogRecord. Emit log di context span yang aktif sehingga
OTel menyertakan trace context. Loki menyimpan `trace_id` dan
`span_id` sebagai structured metadata, bukan index label.

Jangan menambahkan `trace_id`, `span_id`, user ID, request ID, atau path URL
mentah sebagai label Loki. Label index Loki yang dipakai:
`service_name`, `service_namespace`, dan
`deployment_environment_name`.

Di Grafana, datasource Loki sudah memiliki derived field `TraceID` bertipe
label. Panel log error menampilkan link ke Tempo. Datasource Tempo juga sudah
memiliki trace-to-logs dengan query:

```logql
{service_name="chat-api"} | trace_id = "<trace_id>"
```

Aplikasi yang hanya menulis JSON ke stdout tidak otomatis masuk ke Loki karena
Collector pusat tidak membaca filesystem container aplikasi. Gunakan OTel Logs
SDK atau log bridge yang benar-benar mengirim OTLP.

## 5. Contoh inisialisasi Go

Contoh lengkap ada di
[examples/go-demo-app/main.go](examples/go-demo-app/main.go). Kerangka minimum:

```go
package main

import (
    "context"
    "errors"
    "os"
    "time"

    "go.opentelemetry.io/otel"
    "go.opentelemetry.io/otel/attribute"
    "go.opentelemetry.io/otel/exporters/otlp/otlplog/otlploggrpc"
    "go.opentelemetry.io/otel/exporters/otlp/otlpmetric/otlpmetricgrpc"
    "go.opentelemetry.io/otel/exporters/otlp/otlptrace/otlptracegrpc"
    "go.opentelemetry.io/otel/propagation"
    sdklog "go.opentelemetry.io/otel/sdk/log"
    sdkmetric "go.opentelemetry.io/otel/sdk/metric"
    "go.opentelemetry.io/otel/sdk/resource"
    sdktrace "go.opentelemetry.io/otel/sdk/trace"
)

func setupOTel(ctx context.Context) (func(context.Context) error, error) {
    endpoint := os.Getenv("OTEL_EXPORTER_OTLP_ENDPOINT")
    if endpoint == "" {
        endpoint = "http://otel-collector:4317"
    }
    serviceName := os.Getenv("OTEL_SERVICE_NAME")
    if serviceName == "" {
        serviceName = "chat-api"
    }
    attributes := []attribute.KeyValue{
        attribute.String("service.name", serviceName),
        attribute.String("service.version", "1.4.2"),
    }
    if environment := os.Getenv("DEPLOYMENT_ENVIRONMENT"); environment != "" {
        attributes = append(attributes,
            attribute.String("deployment.environment.name", environment))
    }

    res, err := resource.New(ctx,
        resource.WithFromEnv(),
        resource.WithTelemetrySDK(),
        resource.WithAttributes(attributes...),
    )
    if err != nil {
        return nil, err
    }

    traceExporter, err := otlptracegrpc.New(ctx, otlptracegrpc.WithEndpointURL(endpoint))
    if err != nil {
        return nil, err
    }
    traceProvider := sdktrace.NewTracerProvider(
        sdktrace.WithBatcher(traceExporter),
        sdktrace.WithResource(res),
    )

    metricExporter, err := otlpmetricgrpc.New(ctx, otlpmetricgrpc.WithEndpointURL(endpoint))
    if err != nil {
        return nil, err
    }
    meterProvider := sdkmetric.NewMeterProvider(
        sdkmetric.WithReader(sdkmetric.NewPeriodicReader(metricExporter,
            sdkmetric.WithInterval(10*time.Second))),
        sdkmetric.WithResource(res),
    )

    logExporter, err := otlploggrpc.New(ctx, otlploggrpc.WithEndpointURL(endpoint))
    if err != nil {
        return nil, err
    }
    loggerProvider := sdklog.NewLoggerProvider(
        sdklog.WithProcessor(sdklog.NewBatchProcessor(logExporter)),
        sdklog.WithResource(res),
    )

    otel.SetTracerProvider(traceProvider)
    otel.SetMeterProvider(meterProvider)
    otel.SetLoggerProvider(loggerProvider)
    otel.SetTextMapPropagator(propagation.TraceContext{})

    return func(shutdownCtx context.Context) error {
        return errors.Join(
            loggerProvider.Shutdown(shutdownCtx),
            meterProvider.Shutdown(shutdownCtx),
            traceProvider.Shutdown(shutdownCtx),
        )
    }, nil
}
```

Versi module yang dipakai demo:

```text
go.opentelemetry.io/otel v1.47.0
otlptracegrpc/otlpmetricgrpc v1.47.0
otlploggrpc v0.23.0
```

Histogram yang sesuai kontrak dashboard:

```go
duration, err := meter.Float64Histogram(
    "http.server.request.duration",
    metric.WithUnit("s"),
    metric.WithExplicitBucketBoundaries(
        0.005, 0.01, 0.025, 0.05, 0.075, 0.1,
        0.25, 0.5, 0.75, 1, 2.5, 5, 10,
    ),
)
if err != nil {
    return err
}

duration.Record(ctx, elapsed.Seconds(), metric.WithAttributes(
    attribute.String("http.request.method", request.Method),
    attribute.String("http.route", "/v1/orders/:id"),
    attribute.Int("http.response.status_code", statusCode),
))
```

Untuk frontend, worker, scheduled jobs, atau consumer queue, buat span dan
propagasikan W3C `traceparent` pada HTTP/gRPC. Request ID yang berbeda dari
trace ID tidak boleh menjadi label metric.

## 6. Bahasa lain

Semua bahasa memakai environment contract yang sama. Aktifkan OTLP exporter
dan instrumentation untuk HTTP serta logs:

- Java: Java agent OTel dengan env di atas.
- Node.js: `@opentelemetry/auto-instrumentations-node` dan OTLP exporter.
- Python: `opentelemetry-instrument` dan exporter OTLP.
- .NET: distributor OTel dan exporter OTLP.

Tidak ada perubahan endpoint per bahasa. Collector menerima OTLP standard.

## 7. Alert yang bergantung pada aplikasi

- `ServiceHighErrorRate` membutuhkan histogram
  `http_server_request_duration_seconds_count` dengan atribut
  `http.response.status_code`.
- `ServiceHighLatency` membutuhkan histogram duration yang sama.
- `ServiceNoHeartbeat` membutuhkan gauge
  `app.heartbeat.timestamp` setiap 10-15 detik dan minimal satu sample
  sebelumnya. Service yang tidak pernah mengirim data tidak dapat ditemukan
  hanya dari data push; dekomisioning service perlu silence atau pembersihan
  target_info.

## 8. Checklist verifikasi onboarding

1. Aplikasi dapat menjangkau Collector:

```sh
docker compose exec chat-api wget -qO- http://otel-collector:13133/
```

2. Buka Prometheus di http://127.0.0.1:9090 dan query:

```promql
target_info{job=~".*chat-api"}
```

Jika `service.namespace` diisi, label `job` berisi
`namespace/service`; karena itu query memakai pencocokan regex. Label
`deployment_environment_name="prod"` harus ada. Jika tidak ada, periksa
`OTEL_RESOURCE_ATTRIBUTES`.

3. Query metrics aplikasi:

```promql
http_server_request_duration_seconds_count{job="chat-api"}
```

4. Buka Grafana Explore, pilih Loki, dan query:

```logql
{service_name="chat-api", deployment_environment_name="prod"}
```

5. Buka Grafana Explore, pilih Tempo, cari trace dengan:

```traceql
{ resource.service.name = "chat-api" && resource.deployment.environment.name = "prod" }
```

6. Buka dashboard Service Overview dan pilih service serta environment.
7. Klik TraceID pada log error. Trace yang sama harus terbuka di Tempo.
8. Pastikan histogram panel menampilkan exemplar. Jika tidak ada, pastikan
   request metrics direkam dengan context span aktif.
9. Tunggu minimal satu interval evaluation Prometheus, lalu cek
   `/api/v1/rules` di Prometheus memuat alert rule.
10. Hapus service monitoring lokal dari Compose aplikasi; jangan menjalankan
    salinan Grafana/Loki/Tempo/Prometheus per aplikasi.

## 9. Batasan keamanan dan cardinality

- Basic auth hanya autentikasi jaringan. Collector tidak mengikat token ke
  `service.name`; aplikasi tepercaya dapat mengirim label palsu.
- Satu basic credential untuk semua aplikasi mempermudah onboarding tetapi
  menyulitkan audit dan rotasi. Gunakan kredensial per aplikasi atau mTLS pada
  gateway yang mendukung.
- Jangan menaruh token di image, repository, URL, atau label telemetry.
- Jangan memakai `http.route` berisi ID mentah. Gunakan route template.
- Jangan menjadikan `service.instance.id`, pod name, atau trace ID sebagai
  index label Loki.
- Hati-hati dengan atribut metrics yang nilainya tak terbatas. Setiap kombinasi
  label membuat series baru dan menaikkan RAM serta disk Prometheus.

