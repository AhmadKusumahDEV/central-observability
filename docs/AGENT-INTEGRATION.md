# Panduan Agent: Integrasi Project ke Observability Terpusat

Dokumen ini adalah satu file yang dapat diberikan kepada coding agent di repo
aplikasi. Agent harus membaca dokumen ini dari atas ke bawah, lalu menyalin
package dari folder `templates/go-service/pkg/observability` di repo pusat.

Kontrak pusatnya adalah **OTLP**, bukan Go. Package Go di folder template adalah
alat bantu untuk aplikasi Go. Aplikasi Rust, Node.js, Python, Java, .NET, atau
bahasa lain memakai SDK OpenTelemetry resmi untuk bahasanya dan mengirim ke
endpoint yang sama. Yang berbeda hanya kode inisialisasi dan middleware;
Collector, Grafana, Prometheus, Loki, Tempo, Nginx, dan auth pusat tidak perlu
dibuat ulang.

Contoh bahasa yang sudah tersedia di repo:

- Go: package [templates/go-service/pkg/observability](../templates/go-service/pkg/observability/observability.go).
- Node.js: [examples/node](../examples/node/app.js).
- Python: [examples/python](../examples/python/app.py).

Untuk bahasa lain, gunakan exporter OTLP resmi dan pertahankan kontrak resource
`service.name`, `deployment.environment.name`, dan `service.version`. Jika SDK
bahasa tersebut belum menyediakan helper logs/metrics yang lengkap, tambahkan
adapter kecil di aplikasi; itu tetap bukan stack monitoring baru.

| Bahasa | Yang tersedia sekarang | Yang perlu dibuat di aplikasi |
|---|---|---|
| Go | Package observability lengkap | Copy folder dan panggil `Setup` |
| Node.js | Contoh OTLP lengkap | Salin pola `instrumentation.js` |
| Python | Contoh OTLP lengkap | Salin `telemetry.py` |
| Rust | Belum ada contoh di repo | Inisialisasi `opentelemetry-rust`, `tracing`, exporter OTLP, lalu instrumentasi HTTP/database |
| Java/.NET/lain | Belum ada contoh di repo | Gunakan OpenTelemetry SDK resmi dan env OTLP yang sama |

Jadi, untuk Rust kamu tidak membuat ulang Collector, Nginx, atau Grafana. Kamu
hanya membuat satu file bootstrap OpenTelemetry dan instrumentasi framework
Rust yang dipakai. Jika nanti ada beberapa project Rust, file bootstrap itu
bisa dijadikan crate internal bersama.

Jika kamu sudah menyalin `pkg/observability` terlebih dahulu, gunakan
[templates/APP-AGENT-PROMPT.md](../templates/APP-AGENT-PROMPT.md) sebagai
prompt singkat untuk agent aplikasi. Prompt itu sudah mengasumsikan package
tersebut ada, sehingga agent hanya membuat env dan wiring.

## File Markdown di repo aplikasi

Setiap repo aplikasi sebaiknya punya satu `AGENTS.md` di root. Isinya adalah
kontrak khusus aplikasi itu, bukan salinan `AGENTS.md` pusat. Isi minimumnya:

- nama service dan environment;
- bahasa dan folder package/template yang dipakai;
- endpoint same-host atau remote;
- perintah build dan pengecekan;
- larangan menambahkan service monitoring;
- tautan ke dokumen pusat ini.

Contoh konkret ada di
[templates/go-service/AGENTS.md](../templates/go-service/AGENTS.md). Saat
menyalin template ke project baru, ganti nama service dan environment di file
itu.

Jangan menyalin `AGENTS.md` pusat ke repo aplikasi. File pusat itu mengatur
perubahan pada stack observability; file aplikasi mengatur aplikasi yang
mengirim telemetry. Jika agent aplikasi tidak bisa membuka repo pusat, salin
dokumen ini ke `docs/observability/AGENT-INTEGRATION.md` di repo aplikasi dan
perbarui salinannya saat kontrak pusat berubah.

Jika agent hanya menerima satu file, berikan file ini. Jika agent juga dapat
membaca repo pusat, berikan folder
`templates/go-service/pkg/observability`. Folder itu adalah kode yang harus
disalin; folder `stack/` dari package lama tidak boleh disalin.

Dokumen pendukung:

- [README.md](README.md) untuk gambaran stack dan alamat service.
- [NAMING.md](NAMING.md) untuk aturan nama service dan label.
- [TROUBLESHOOTING.md](TROUBLESHOOTING.md) jika data tidak masuk.
- [OPERATIONS.md](OPERATIONS.md) untuk retensi, backup, dan upgrade.
- [INTEGRATION.md](INTEGRATION.md) untuk penjelasan umum integrasi.
- [ONLINE-ACCESS.md](ONLINE-ACCESS.md) untuk akses remote, Nginx, dan
  Cloudflare.
- [Template Go](../templates/go-service/README.md) untuk aplikasi contoh yang
  sudah mengirim traces, metrics, dan logs.
- [VERIFICATION.md](VERIFICATION.md) untuk hasil uji template terhadap stack
  pusat.

## 1. Batas yang tidak boleh dilanggar

Project aplikasi hanya menjalankan aplikasinya sendiri. Jangan menambahkan
Grafana, Prometheus, Loki, Tempo, OpenTelemetry Collector, atau Alertmanager ke
Compose project aplikasi.

Aplikasi hanya boleh mengirim OTLP ke Collector pusat. Aplikasi tidak boleh
mengirim logs langsung ke Loki, metrics langsung ke Prometheus, atau traces
langsung ke Tempo. Collector pusat yang merutekan semuanya.

Gunakan resource attribute berikut di setiap aplikasi:

| Atribut | Contoh | Wajib |
|---|---|---|
| `service.name` | `order-api` | Ya |
| `deployment.environment.name` | `dev`, `staging`, `prod` | Ya |
| `service.version` | `1.4.2` atau commit SHA | Ya |
| `service.namespace` | `acme`, `payments` | Opsional |

Jangan memakai nilai `latest` untuk image atau library. Jangan memakai URL
mentah, ID pengguna, atau trace ID sebagai label metrics.

## 2. File yang perlu masuk ke project baru

Salin atau buat file berikut di project baru:

| Path di project baru | Wajib | Isi |
|---|---|---|
| `pkg/observability/observability.go` | Ya | Package siap pakai dari template pusat |
| `otel.env` | Ya | Nama service, environment, endpoint Collector |
| `docker-compose.yml` | Ya | Aplikasi dan external network `monitoring` |
| perubahan di `main.go` | Ya | Panggil `observability.Setup` sebelum server |
| `go.mod` dan `go.sum` | Ya | Dependency OpenTelemetry yang dipin |
| `Dockerfile` | Opsional | Cara membangun image aplikasi |

Yang tidak perlu disalin:

- `templates/go-service/cmd/app/main.go` adalah contoh aplikasi, bukan file
  yang harus ditimpa ke aplikasi Anda.
- Folder `stack/` dari package lama hanya untuk pengembangan lokal. Jangan
  membuat stack monitoring di project aplikasi.
- Folder `pkg/observability/stack` dari repo `go-chat` tidak boleh disalin.
  Folder itu menjalankan stack lokal versi lama dan dapat bentrok dengan stack
  pusat.

## 3. Tentukan nama service dan environment

Baca [NAMING.md](NAMING.md). Nilai yang dipakai di contoh:

```dotenv
OTEL_SERVICE_NAME=example-go-service
OTEL_RESOURCE_ATTRIBUTES=deployment.environment.name=dev,service.namespace=examples,service.version=0.1.0
```

Ganti `example-go-service` dengan nama project yang sebenarnya. Jangan
menambahkan `-dev`, `-prod`, atau nomor versi ke `service.name`.

Contoh yang benar:

```dotenv
OTEL_SERVICE_NAME=order-api
OTEL_RESOURCE_ATTRIBUTES=deployment.environment.name=prod,service.namespace=acme,service.version=1.4.2
```

Contoh yang salah:

```dotenv
OTEL_SERVICE_NAME=OrderAPI-prod-v2
```

Nama di atas salah karena memakai huruf besar, mencampur environment, dan
memasukkan versi ke nama service.

## 4. Salin package observability

Package ini sengaja berupa satu file di satu folder. Tidak ada import internal
yang perlu diubah, jadi package dapat disalin ke project mana pun.

Windows PowerShell:

```powershell
New-Item -ItemType Directory -Force -Path .\pkg | Out-Null
Copy-Item -Recurse -Force `
  D:\appwebsite\central-observability\templates\go-service\pkg\observability `
  .\pkg\observability
```

Linux atau macOS:

```sh
mkdir -p ./pkg
cp -R /path/ke/central-observability/templates/go-service/pkg/observability ./pkg/observability
```

Setelah disalin, import di aplikasi mengikuti nama module project. Contoh jika
module project adalah `github.com/AhmadKusumahDEV/order-api`:

```go
import "github.com/AhmadKusumahDEV/order-api/pkg/observability"
```

Jika module project adalah `example.com/order-api`:

```go
import "example.com/order-api/pkg/observability"
```

Jangan mengimpor `example.com/go-service-template/pkg/observability` dari luar
template. Ganti path import sesuai module project setelah folder disalin.

## 5. Tambahkan dependency dengan versi pin

Jalankan dari root project aplikasi:

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
go get go.opentelemetry.io/contrib/instrumentation/net/http/otelhttp@v0.72.0
go mod tidy
```

Jika aplikasi memakai database SQL, tambahkan juga:

```sh
go get github.com/XSAM/otelsql@v0.44.0
```

Versi di atas adalah versi yang dipakai template. Jangan mencampur OTel core
`v1.46.0` dengan exporter `v1.43.0`; pilih satu set dan jalankan `go mod tidy`.

## 6. Buat file `otel.env`

Buat file `otel.env` di root project:

```dotenv
# Alamat Collector dari dalam container aplikasi.
OTEL_EXPORTER_OTLP_ENDPOINT=http://otel-collector:4317
OTEL_EXPORTER_OTLP_PROTOCOL=grpc

# Nama stabil service. Ganti dengan nama project Anda.
OTEL_SERVICE_NAME=example-go-service

# Pisahkan atribut dengan koma tanpa spasi.
OTEL_RESOURCE_ATTRIBUTES=deployment.environment.name=dev,service.namespace=examples,service.version=0.1.0

# 1 berarti semua trace disimpan. Turunkan untuk trafik besar.
OTEL_TRACES_SAMPLER=parentbased_traceidratio
OTEL_TRACES_SAMPLER_ARG=1

# Interval pengiriman metrics dalam milidetik.
OTEL_METRIC_EXPORT_INTERVAL=10000

# Kompresi payload OTLP.
OTEL_EXPORTER_OTLP_COMPRESSION=gzip

# Level log: debug, info, warn, atau error.
OBSERVABILITY_LOG_LEVEL=info

# Heartbeat untuk alert service down.
OBSERVABILITY_HEARTBEAT_INTERVAL=15

# Alamat HTTP aplikasi.
APP_ADDR=:8080
```

Penjelasan setiap baris:

| Variabel | Arti |
|---|---|
| `OTEL_EXPORTER_OTLP_ENDPOINT` | Alamat Collector. Dari container aplikasi gunakan `otel-collector:4317`. |
| `OTEL_EXPORTER_OTLP_PROTOCOL` | `grpc` untuk same-host; `http/protobuf` untuk remote melalui Cloudflare Tunnel. |
| `OTEL_SERVICE_NAME` | Nama service yang muncul di dashboard. |
| `OTEL_RESOURCE_ATTRIBUTES` | Environment, namespace, dan versi. |
| `OTEL_TRACES_SAMPLER` | Strategi sampling trace. |
| `OTEL_TRACES_SAMPLER_ARG` | Rasio sampling, `0.10` berarti sekitar 10 persen. |
| `OTEL_METRIC_EXPORT_INTERVAL` | Interval pengiriman metrics. |
| `OTEL_EXPORTER_OTLP_COMPRESSION` | `gzip` untuk mengurangi bandwidth. |
| `OBSERVABILITY_LOG_LEVEL` | Level log yang ditulis ke stdout dan OTLP. |
| `OBSERVABILITY_HEARTBEAT_INTERVAL` | Interval heartbeat service. |
| `APP_ADDR` | Alamat HTTP aplikasi di dalam container. |

Untuk server remote, jangan membuka port 4317 atau 4318 ke internet. VPS pusat
memakai Cloudflare Tunnel dan Nginx; endpoint publiknya adalah OTLP/HTTP di
`https://otlp.madgo.my.id`. Contoh env remote:

```dotenv
OTEL_EXPORTER_OTLP_ENDPOINT=https://otlp.madgo.my.id
OTEL_EXPORTER_OTLP_PROTOCOL=http/protobuf
OTEL_EXPORTER_OTLP_HEADERS=Authorization=Basic%20BASE64_URLENCODED
OTEL_EXPORTER_OTLP_TIMEOUT=10000
OTEL_EXPORTER_OTLP_COMPRESSION=gzip
```

`BASE64_URLENCODED` adalah base64 dari `user:password` yang sudah di-URL-encode.
Buat user per aplikasi dengan
`deploy/nginx/add-otlp-client.sh`; script mencetak nilai siap pakai. Jangan
memakai satu password untuk semua aplikasi. Jika Cloudflare Access Service Auth
aktif, tambahkan `CF-Access-Client-Id` dan `CF-Access-Client-Secret` ke nilai
header yang sama. Jangan commit credential ke Git.

Package template mendukung `OTEL_EXPORTER_OTLP_PROTOCOL=http/protobuf`; cukup
ganti env, tanpa mengubah kode aplikasi. Aplikasi same-host tetap boleh memakai
gRPC `http://otel-collector:4317`.

Detail keamanan, DNS, dan langkah Cloudflare ada di
[ONLINE-ACCESS.md](ONLINE-ACCESS.md).

## 7. Panggil Setup di `main.go`

Contoh berikut adalah urutan yang benar. `Setup` harus dipanggil sebelum HTTP
server mulai melayani request. `Shutdown` dipanggil setelah server berhenti.

```go
package main

import (
	"context"
	"errors"
	"fmt"
	"net/http"
	"os"
	"os/signal"
	"syscall"
	"time"

	"example.com/order-api/pkg/observability"
)

func main() {
	ctx, stop := signal.NotifyContext(context.Background(), os.Interrupt, syscall.SIGTERM)
	defer stop()

	stack, err := observability.Setup(ctx, observability.ConfigFromEnv())
	if err != nil {
		fmt.Fprintf(os.Stderr, "setup observability: %v\n", err)
		os.Exit(1)
	}

	mux := http.NewServeMux()
	mux.HandleFunc("GET /healthz", func(w http.ResponseWriter, _ *http.Request) {
		w.WriteHeader(http.StatusOK)
		_, _ = w.Write([]byte("ok\n"))
	})

	server := &http.Server{
		Addr:              ":8080",
		Handler:           stack.HandlerWithRoute(mux, routeForRequest),
		ReadHeaderTimeout: 5 * time.Second,
	}

	go func() {
		stack.Logger.InfoContext(ctx, "http server listening", "address", server.Addr)
		if err := server.ListenAndServe(); err != nil && !errors.Is(err, http.ErrServerClosed) {
			stack.Logger.ErrorContext(ctx, "http server stopped", "error", err)
			stop()
		}
	}()

	<-ctx.Done()
	shutdownCtx, cancel := context.WithTimeout(context.Background(), 10*time.Second)
	defer cancel()
	_ = server.Shutdown(shutdownCtx)
	_ = stack.Shutdown(shutdownCtx)
}

func routeForRequest(r *http.Request) string {
	switch r.URL.Path {
	case "/healthz", "/work":
		return r.URL.Path
	default:
		return "unmatched"
	}
}
```

Ganti `example.com/order-api/pkg/observability` dengan module project Anda.
Untuk `http.ServeMux`, gunakan `HandlerWithRoute` seperti contoh karena pola
route baru diketahui setelah mux memilih handler. Kembalikan route template,
bukan URL mentah. Jika aplikasi memakai Gin atau Echo, baca bagian 9 karena
route template perlu diambil dari framework, bukan dari `http.Request.Pattern`.

`stack` menyediakan:

| Field atau method | Kegunaan |
|---|---|
| `stack.Logger` | `*slog.Logger` yang menulis JSON ke stdout dan OTLP logs. |
| `stack.Tracer` | Tracer untuk span buatan aplikasi. |
| `stack.Meter` | Meter untuk counter, gauge, dan histogram buatan aplikasi. |
| `stack.Handler` | Span HTTP server, histogram durasi, dan access log. |
| `stack.HandlerWithRoute` | Seperti `Handler`, dengan route template dari aplikasi. |
| `stack.HTTPClient` | HTTP client dengan span keluar dan propagasi trace. |
| `stack.RecordHTTPDuration` | Mencatat histogram HTTP secara manual. |
| `stack.LogRequest` | Menulis access log dengan konteks trace. |
| `stack.Shutdown` | Mengirim sisa logs, metrics, dan traces. |

## 8. Hubungkan database dan HTTP client

Untuk database SQL, gunakan `otelsql` agar query membuat span dan meneruskan
context request:

```go
import (
	"context"
	"database/sql"

	"github.com/XSAM/otelsql"
	_ "modernc.org/sqlite"
)

func openDatabase(ctx context.Context) (*sql.DB, error) {
	db, err := otelsql.Open("sqlite", "file:order?mode=memory&cache=shared")
	if err != nil {
		return nil, err
	}
	db.SetMaxOpenConns(1)
	if _, err := db.ExecContext(ctx,
		"CREATE TABLE IF NOT EXISTS work_items (id INTEGER PRIMARY KEY AUTOINCREMENT, created_at TEXT NOT NULL)",
	); err != nil {
		_ = db.Close()
		return nil, err
	}

	return db, nil
}
```

Untuk PostgreSQL, ganti driver dan DSN, tetapi tetap gunakan `otelsql.Open`.
Jangan memakai `database/sql` tanpa context-aware method (`ExecContext`,
`QueryContext`, `PrepareContext`) karena span database akan kehilangan trace.

Untuk HTTP client keluar:

```go
import (
	"context"
	"io"
	"net/http"
	"time"

	"example.com/order-api/pkg/observability"
)

func callDownstream(ctx context.Context, stack *observability.Stack) error {
	client := stack.HTTPClient(&http.Client{Timeout: 5 * time.Second})
	request, err := http.NewRequestWithContext(ctx, http.MethodGet, "https://downstream.example.com/health", nil)
	if err != nil {
		return err
	}
	response, err := client.Do(request)
	if err != nil {
		return err
	}
	defer response.Body.Close()
	_, _ = io.Copy(io.Discard, io.LimitReader(response.Body, 1024))

	return nil
}
```

`stack.HTTPClient` menambahkan span HTTP keluar dan header `traceparent`,
sehingga trace berlanjut ke service tujuan.

## 9. Gin, Echo, dan framework lain

`stack.Handler` cocok untuk `net/http` dan `http.ServeMux`. Untuk Gin atau Echo,
gunakan instrumentation resmi agar route template seperti `/v1/orders/:id`
masuk ke span dan metrics. Jangan membungkus seluruh engine Gin/Echo dengan
`stack.Handler`; route dari framework tidak terlihat di sana dan labelnya akan
menjadi `unmatched`.

Gin. Simpan sebagai `internal/httpserver/gin.go`; sesuaikan nama package dan
module project:

```go
package httpserver

import (
	"time"

	"example.com/order-api/pkg/observability"
	"github.com/gin-gonic/gin"
	"go.opentelemetry.io/contrib/instrumentation/github.com/gin-gonic/gin/otelgin"
)

func NewGinRouter(stack *observability.Stack) *gin.Engine {
	router := gin.New()
	router.Use(otelgin.Middleware("order-api"))
	router.Use(func(c *gin.Context) {
		started := time.Now()
		c.Next()
		stack.LogRequest(
			c.Request.Context(),
			c.Request.Method,
			c.FullPath(),
			c.Writer.Status(),
			time.Since(started),
		)
	})

	return router
}
```

Tambahkan dependency versi pin:

```sh
go get go.opentelemetry.io/contrib/instrumentation/github.com/gin-gonic/gin/otelgin@v0.72.0
```

Echo. Simpan sebagai `internal/httpserver/echo.go`; sesuaikan nama package dan
module project:

```go
package httpserver

import (
	"time"

	"example.com/order-api/pkg/observability"
	"github.com/labstack/echo/v4"
	echootel "github.com/labstack/echo-otel/v4"
)

func NewEchoServer(stack *observability.Stack) *echo.Echo {
	server := echo.New()
	server.Use(echootel.NewMiddleware("order-api"))
	server.Use(func(next echo.HandlerFunc) echo.HandlerFunc {
		return func(c echo.Context) error {
			started := time.Now()
			err := next(c)
			stack.LogRequest(
				c.Request().Context(),
				c.Request().Method,
				c.Path(),
				c.Response().Status,
				time.Since(started),
			)

			return err
		}
	})

	return server
}
```

Tambahkan dependency versi pin:

```sh
go get github.com/labstack/echo-otel/v4@v4.0.0
```

Jangan memakai `c.Request().URL.Path` sebagai route. URL mentah membuat satu
series metrics untuk setiap ID. Gunakan `c.FullPath()` pada Gin atau `c.Path()`
pada Echo.

## 10. Project yang sudah punya `go-chat/pkg/observability`

Package lama di `go-chat` tidak boleh disalin apa adanya ke stack pusat. Tiga
bagian berikut tidak cocok:

| Bagian package lama | Masalah untuk stack pusat | Pengganti |
|---|---|---|
| `logging.LokiConfig` | Mengirim logs langsung ke Loki. Loki pusat berada di network internal dan tidak dapat dijangkau dari network `monitoring`. | OTLP logs lewat Collector. |
| `metrics.New` dan `/metrics` | Memakai Prometheus pull. Prometheus pusat tidak melakukan scrape ke container aplikasi. | OTLP metrics lewat Collector. |
| Label `service` dan `env` | Dashboard pusat memakai `service_name` dan `deployment_environment_name` dari resource. | Resource attribute OpenTelemetry. |

Package lama juga memakai nama metric `app_http_requests_total` dan
`app_http_request_duration_seconds`. Dashboard pusat memakai
`http_server_request_duration_seconds_*`. Karena itu, menyalin package lama
tanpa perubahan akan menghasilkan dashboard kosong meskipun aplikasi terlihat
sehat.

Pilihan yang disarankan untuk setiap project yang sudah memakai package lama:

1. Salin package baru ke `pkg/observability` atau nama lain yang tidak bentrok,
   misalnya `pkg/otelstack`.
2. Ubah entrypoint project agar memanggil `observability.Setup` dari package
   baru. Jangan memanggil dua `Setup` sekaligus.
3. Hapus `OBSERVABILITY_LOKI_URL` dari environment project. Jangan mengarahkan
   aplikasi ke Loki pusat.
4. Hentikan penggunaan `metrics.New` sebagai jalur utama. Jika endpoint
   `/metrics` masih dipakai untuk debugging lokal, biarkan, tetapi jangan
   mengharapkan datanya masuk ke Prometheus pusat.
5. Ganti middleware HTTP lama dengan `stack.Handler` untuk `net/http`, atau
   `otelgin`/`echo-otel` plus `stack.LogRequest` untuk Gin/Echo.
6. Pastikan `service.name`, `deployment.environment.name`, dan `service.version`
   ada di resource. Jangan mengandalkan label `service` atau `env` lama.
7. Jalankan aplikasi, lalu verifikasi dengan query di bagian 12.

Jika Anda harus mempertahankan API package lama karena banyak import, ubah isi
package lama secara bertahap:

- Tambahkan OTLP metric exporter dan meter provider di `observability.Setup`.
- Tambahkan OTLP log exporter dan `slog` handler; hapus pengiriman langsung ke
  Loki atau biarkan sebagai opsi lokal yang tidak dipakai di pusat.
- Ubah const label Prometheus `service` menjadi resource `service.name`; jangan
  menambahkan label manual yang berbeda dari resource.
- Ubah nama instrument HTTP menjadi `http.server.request.duration` dengan unit
  `s`, dan atribut `http.request.method`, `http.route`,
  `http.response.status_code`.
- Samakan versi OTel core, SDK, dan exporter. Jangan mencampur `v1.46.0` dengan
  `v1.43.0`.

Jangan menyalin folder `pkg/observability/stack` dari `go-chat`. Folder itu
berisi stack monitoring lokal dan tidak boleh berjalan bersama stack pusat.

## 11. Compose project aplikasi

File berikut hanya berisi aplikasi dan external network. Tidak ada service
monitoring di dalamnya.

```yaml
name: order-api

services:
  app:
    build: .
    image: registry.example.com/order-api:1.4.2
    restart: unless-stopped
    env_file:
      - otel.env
    ports:
      - "127.0.0.1:8080:8080"
    networks:
      - monitoring
    healthcheck:
      test: ["CMD-SHELL", "wget -qO- http://127.0.0.1:8080/healthz | grep -q ok"]
      interval: 10s
      timeout: 3s
      retries: 5

networks:
  monitoring:
    external: true
    name: monitoring
```

Ganti `order-api`, image, dan port dengan nilai project. Network `monitoring`
harus sudah dibuat oleh stack pusat. Jangan menambahkan `networks: monitoring`
ke Compose aplikasi jika network itu belum ada; jalankan stack pusat lebih dulu.

## 12. Verifikasi di Grafana

Buka `http://127.0.0.1:3000`. Pilih service dan environment yang baru dikirim.

### Prometheus

Request rate:

```promql
sum by (service_name) (
  rate(http_server_request_duration_seconds_count{
    service_name="order-api",
    deployment_environment_name="dev"
  }[5m])
)
```

Error rate 5xx dalam persen:

```promql
100 *
sum by (service_name) (
  rate(http_server_request_duration_seconds_count{
    service_name="order-api",
    deployment_environment_name="dev",
    http_response_status_code=~"5.."
  }[5m])
)
/
clamp_min(
  sum by (service_name) (
    rate(http_server_request_duration_seconds_count{
      service_name="order-api",
      deployment_environment_name="dev"
    }[5m])
  ),
  1e-9
)
```

Latency p95:

```promql
histogram_quantile(
  0.95,
  sum by (le) (
    rate(http_server_request_duration_seconds_bucket{
      service_name="order-api",
      deployment_environment_name="dev"
    }[5m])
  )
)
```

Heartbeat terakhir:

```promql
last_over_time(app_heartbeat_timestamp_seconds{
  service_name="order-api",
  deployment_environment_name="dev"
}[2m])
```

### Loki

Semua log service:

```logql
{service_name="order-api", deployment_environment_name="dev"}
```

Log error:

```logql
{service_name="order-api", deployment_environment_name="dev"}
  | detected_level=~"(?i)error|fatal"
```

Log untuk satu trace:

```logql
{service_name="order-api"} | trace_id="4bf92f3577b34da6a3ce929d0e0e4736"
```

Ganti trace ID dengan nilai dari response aplikasi atau dari Tempo. Di Grafana
Explore, baris log yang memiliki `trace_id` menampilkan tautan ke Tempo.

### Tempo

Semua trace service:

```traceql
{
  resource.service.name = "order-api" &&
  resource.deployment.environment.name = "dev"
}
```

Trace lambat di atas 500 ms:

```traceql
{
  resource.service.name = "order-api" &&
  resource.deployment.environment.name = "dev" &&
  duration > 500ms
}
with (most_recent=true)
```

Trace dengan error:

```traceql
{
  resource.service.name = "order-api" &&
  resource.deployment.environment.name = "dev" &&
  status = error
}
```

## 13. Cek dari terminal

Jalankan dari host yang menjalankan stack pusat:

```sh
curl -fsS http://127.0.0.1:13133/
curl -fsS http://127.0.0.1:9090/-/ready
curl -fsS http://127.0.0.1:3100/ready
curl -fsS http://127.0.0.1:3200/ready
```

Cek metrics service di Prometheus:

```sh
curl -fsS --get http://127.0.0.1:9090/api/v1/query \
  --data-urlencode 'query=count by (service_name, deployment_environment_name) (http_server_request_duration_seconds_count{service_name="order-api"})'
```

Cek log terbaru di Loki:

```sh
curl -fsS --get http://127.0.0.1:3100/loki/api/v1/query_range \
  --data-urlencode 'query={service_name="order-api", deployment_environment_name="dev"}' \
  --data-urlencode 'limit=5'
```

Cek trace terbaru di Tempo:

```sh
curl -fsS --get http://127.0.0.1:3200/api/search \
  --data-urlencode 'q={ resource.service.name = "order-api" }' \
  --data-urlencode 'limit=5'
```

Jika salah satu perintah gagal, baca [TROUBLESHOOTING.md](TROUBLESHOOTING.md).

## 14. Alert khusus service

Alert disimpan di repo pusat, bukan di repo aplikasi. Buat file
`config/prometheus/rules/order-api.yml` di repo pusat:

```yaml
groups:
  - name: order-api-alerts
    interval: 30s
    rules:
      - alert: OrderApiHighErrorRate
        expr: |
          100 *
          sum by (service_name, job, deployment_environment_name) (
            rate(http_server_request_duration_seconds_count{
              service_name="order-api",
              deployment_environment_name="prod",
              http_response_status_code=~"5.."
            }[5m])
          )
          /
          clamp_min(
            sum by (service_name, job, deployment_environment_name) (
              rate(http_server_request_duration_seconds_count{
                service_name="order-api",
                deployment_environment_name="prod"
              }[5m])
            ),
            1e-9
          )
          > 5
        for: 10m
        labels:
          severity: critical
          service: order-api
          environment: prod
        annotations:
          summary: "Error rate di atas 5 persen untuk order-api"
          description: "Rasio 5xx saat ini {{ $value | printf \"%.2f\" }} persen di prod."
```

Tambahkan test rule di `config/prometheus/tests/order-api.test.yml`:

```yaml
rule_files:
  - ../rules/order-api.yml

evaluation_interval: 1m

tests:
  - interval: 1m
    input_series:
      - series: 'http_server_request_duration_seconds_count{service_name="order-api",job="acme/order-api",deployment_environment_name="prod",http_response_status_code="200"}'
        values: '0+60x20'
      - series: 'http_server_request_duration_seconds_count{service_name="order-api",job="acme/order-api",deployment_environment_name="prod",http_response_status_code="500"}'
        values: '0+5x20'
    alert_rule_test:
      - eval_time: 20m
        alertname: OrderApiHighErrorRate
        exp_alerts:
          - exp_labels:
              severity: critical
              service: order-api
              environment: prod
              service_name: order-api
              job: acme/order-api
              deployment_environment_name: prod
            exp_annotations:
              summary: "Error rate di atas 5 persen untuk order-api"
              description: "Rasio 5xx saat ini 7.69 persen di prod."
```

Jalankan di repo pusat:

```sh
make test-rules
curl -X POST http://127.0.0.1:9090/-/reload
```

## 15. Checklist akhir

- [ ] Folder `pkg/observability` disalin dari template pusat, bukan dari
      `go-chat/pkg/observability/stack`.
- [ ] `OTEL_SERVICE_NAME` mengikuti [NAMING.md](NAMING.md).
- [ ] `deployment.environment.name` berisi `dev`, `staging`, atau `prod`.
- [ ] Aplikasi same-host memakai `otel-collector:4317`/`4318`; aplikasi remote
      memakai `https://otlp.madgo.my.id` dengan `http/protobuf`.
- [ ] Traces, metrics, dan logs sama-sama memakai endpoint Collector.
- [ ] Tidak ada `OBSERVABILITY_LOKI_URL` yang mengarah ke Loki pusat.
- [ ] `observability.Setup` dipanggil sebelum HTTP server.
- [ ] `stack.Shutdown` dipanggil setelah HTTP server berhenti.
- [ ] HTTP server memakai `stack.Handler` atau `otelgin`/`echo-otel`.
- [ ] HTTP client memakai `stack.HTTPClient` atau `otelhttp.NewTransport`.
- [ ] Database memakai `otelsql` atau instrumentasi resmi yang setara.
- [ ] Nama route memakai template, bukan URL mentah.
- [ ] `http.server.request.duration` muncul di Prometheus.
- [ ] Log muncul di Loki dan memiliki `trace_id`.
- [ ] Trace muncul di Tempo dan dapat dibuka dari log.
- [ ] `app_heartbeat_timestamp_seconds` muncul setiap 10-15 detik.
- [ ] `docker compose config --quiet` lulus di project aplikasi.
- [ ] Tidak ada Grafana, Prometheus, Loki, Tempo, Collector, atau Alertmanager
      di Compose project aplikasi.
- [ ] Alert khusus service ditambahkan di repo pusat dan `make test-rules`
      lulus.

## 16. Urutan kerja yang harus dilakukan agent

1. Baca [NAMING.md](NAMING.md) dan tentukan nama service final.
2. Salin folder `pkg/observability` dari template pusat. Jika folder itu sudah
   ada di project, lewati langkah ini.
3. Tambahkan dependency dengan versi pin dan jalankan `go mod tidy`.
4. Buat `otel.env` dari contoh di bagian 6.
5. Ubah `main.go` mengikuti bagian 7.
6. Sambungkan database dan HTTP client mengikuti bagian 8.
7. Tambahkan external network `monitoring` mengikuti bagian 11.
8. Jalankan `go build ./...` dan `docker compose config --quiet`.
9. Jalankan aplikasi dan buka endpoint `/healthz`, `/work`, dan `/error`.
10. Verifikasi PromQL, LogQL, dan TraceQL mengikuti bagian 12.
11. Laporkan hasil verifikasi dan bagian yang belum dapat diuji.

Dokumentasi resmi:

- [OpenTelemetry Go](https://opentelemetry.io/docs/languages/go/)
- [OpenTelemetry Rust](https://opentelemetry.io/docs/languages/rust/)
- [OpenTelemetry JavaScript](https://opentelemetry.io/docs/languages/js/)
- [OpenTelemetry Python](https://opentelemetry.io/docs/languages/python/)
- [OpenTelemetry Protocol](https://opentelemetry.io/docs/specs/otlp/)
- [OTLP exporter environment variables](https://opentelemetry.io/docs/languages/sdk-configuration/otlp-exporter/)
- [OpenTelemetry Collector](https://opentelemetry.io/docs/collector/)
- [Prometheus dan OpenTelemetry](https://prometheus.io/docs/guides/opentelemetry/)
- [Loki OTLP ingestion](https://grafana.com/docs/loki/latest/send-data/otel/)
- [Grafana Tempo](https://grafana.com/docs/tempo/latest/)
- [Grafana provisioning](https://grafana.com/docs/grafana/latest/administration/provisioning/)
