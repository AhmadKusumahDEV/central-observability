# Aturan Nama dan Label

Nama yang konsisten membuat dashboard, alert, dan pencarian log bekerja tanpa
konfigurasi khusus per aplikasi.

## Nama service

Gunakan nilai berikut untuk `OTEL_SERVICE_NAME`:

```dotenv
OTEL_SERVICE_NAME=chat-api
```

Aturan:

- Huruf kecil, angka, dan tanda hubung.
- Panjang 3-63 karakter.
- Stabil selama service itu ada.
- Menjelaskan fungsi, bukan pemilik repository atau nomor versi.
- Jangan mencantumkan environment di nama.

Contoh benar:

| Service | `service.name` |
|---|---|
| API percakapan | `chat-api` |
| Worker antrean order | `order-worker` |
| Gateway pembayaran | `payment-gateway` |

Contoh salah:

| Nilai | Masalah |
|---|---|
| `ChatAPI` | Huruf besar membuat pencarian tidak konsisten |
| `chat-api-prod` | Environment harus berada di resource attribute |
| `chat_api` | Gunakan tanda hubung, bukan underscore |
| `chat-api-v2` | Versi harus berada di `service.version` |
| `my-service-123` | ID acak membuat series baru setiap deploy |

## Namespace

`service.namespace` menjelaskan kelompok pemilik atau domain bisnis,
misalnya `acme`, `payments`, atau `internal-platform`.
Nilai ini opsional. Gunakan nilai yang stabil dan rendah cardinality.

Prometheus menggabungkan namespace dan service menjadi label `job`:

```text
service.namespace=acme
service.name=chat-api
job=acme/chat-api
```

Dashboard memakai label `service_name`, sehingga filter tetap menampilkan
`chat-api` meskipun namespace diisi.

## Environment

Gunakan `deployment.environment.name` dengan salah satu nilai:

- `dev`
- `staging`
- `prod`

```dotenv
OTEL_RESOURCE_ATTRIBUTES=deployment.environment.name=prod
```

Jangan memakai `deployment.environment` untuk data baru. Jangan membuat
environment per developer atau per commit.

## Versi

Gunakan `service.version` berisi versi rilis atau commit SHA:

```dotenv
OTEL_RESOURCE_ATTRIBUTES=deployment.environment.name=prod,service.version=1.4.2
```

Versi boleh berubah setiap deploy. Nama service dan environment tidak boleh
berubah hanya karena versi baru.

## Resource attributes

Resource attribute menjelaskan aplikasi secara keseluruhan. Nilai berikut
dipromosikan oleh Prometheus dan dipakai dashboard:

| Resource attribute | Label Prometheus | Label Loki |
|---|---|---|
| `service.name` | `service_name` | `service_name` |
| `service.namespace` | `service_namespace` | `service_namespace` |
| `service.version` | `service_version` | metadata |
| `deployment.environment.name` | `deployment_environment_name` | `deployment_environment_name` |

Jangan mengulang `service_name` sebagai atribut metric manual kecuali
SDK memang menghasilkan label tersebut dari resource. Kontrak stack mengikuti
resource attributes, bukan salinan manual yang dapat berbeda.

## Label metrics

Metrics harus memakai atribut dengan jumlah nilai terbatas:

```text
http.request.method=GET
http.route=/v1/orders/:id
http.response.status_code=200
```

Jangan pakai:

```text
url.full=https://api.example.com/orders/123?token=...
user.id=982374
request.id=8f7a...
trace.id=...
timestamp=2026-10-28T20:00:00Z
```

Nilai seperti `user.id`, URL dengan ID, trace ID, dan request ID membuat
series metrics baru terus bertambah. Simpan nilai tersebut di span atau log.

## Nama instrument

Gunakan nama instrument OpenTelemetry dan biarkan Collector/Prometheus
menerjemahkannya:

```text
http.server.request.duration
unit: s
type: histogram
```

Jangan membuat nama berbeda per aplikasi seperti
`chat_api_latency_ms` dan `order_api_latency_ms`. Dashboard
mengandalkan nama instrument standar.

## Logs

Log yang dikirim melalui OTLP harus memiliki:

- timestamp;
- severity;
- body;
- trace context;
- atribut route/status bila relevan.

`trace_id` dan `span_id` disimpan sebagai structured metadata,
bukan index label. Jangan membuat label Loki dinamis dari log message.

## Atribut HTTP

Gunakan template route agar nilai tetap terbatas:

| Benar | Salah |
|---|---|
| `/v1/orders/:id` | `/v1/orders/81273` |
| `/users/:userID` | `/users/alice@example.com` |
| `/search` | `/search?q=rahasia` |

## Ringkasan cepat

```dotenv
OTEL_SERVICE_NAME=chat-api
OTEL_RESOURCE_ATTRIBUTES=deployment.environment.name=prod,service.namespace=acme,service.version=1.4.2
```

Jika ragu, pilih nama yang stabil, huruf kecil, dan tidak mengandung
environment, versi, ID pengguna, atau nilai acak.

Lanjutkan ke [INTEGRATION.md](INTEGRATION.md) atau periksa nama yang sudah
masuk dengan [TROUBLESHOOTING.md](TROUBLESHOOTING.md).

