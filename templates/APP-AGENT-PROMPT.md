# Prompt untuk Agent Aplikasi

Gunakan file ini setelah folder `pkg/observability` disalin ke repo aplikasi.
Ganti nilai di dalam `<...>` sebelum memberikan prompt ini kepada agent.

---

Integrasikan project Go ini ke observability pusat yang sudah berjalan.

Identitas:

- `service.name`: `<nama-service>`
- `deployment.environment.name`: `<dev|staging|prod>`
- `service.namespace`: `<namespace>`
- `service.version`: `<versi>`

Fakta yang harus dipakai:

- Folder `pkg/observability` sudah ada di project ini. Jangan menyalin ulang
  package dari repo pusat dan jangan menulis package observability baru.
- Jangan menyalin `go-chat/pkg/observability` atau folder `stack/`.
- Jangan menambahkan Grafana, Prometheus, Loki, Tempo, Collector, atau
  Alertmanager ke Compose project ini.
- Dokumen integrasi pusat ada di
  `docs/observability/AGENT-INTEGRATION.md`. Baca dokumen itu sebelum mengubah
  kode.

Pilih endpoint sesuai lokasi aplikasi:

- Docker di VPS yang sama: `http://otel-collector:4317`, protocol `grpc`, dan
  service join external network `monitoring`.
- Aplikasi native di VPS yang sama: `http://127.0.0.1:4317`, protocol `grpc`.
- Server lain: `https://otlp.madgo.my.id`, protocol `http/protobuf`, dengan
  header Basic auth dari file secret. Jangan menaruh password di repository.

Langkah kerja:

1. Periksa `pkg/observability/observability.go` dan gunakan API yang sudah ada:
   `Setup`, `ConfigFromEnv`, `Shutdown`, `HandlerWithRoute`, `HTTPClient`,
   `Logger`, `Tracer`, dan `Meter`.
2. Tambahkan dependency OTel dengan versi pin dari
   `docs/observability/AGENT-INTEGRATION.md`; jalankan `go mod tidy`.
3. Buat `otel.env.example` tanpa credential asli. Isi minimal:
   `OTEL_SERVICE_NAME`, `OTEL_RESOURCE_ATTRIBUTES`,
   `OTEL_EXPORTER_OTLP_ENDPOINT`, `OTEL_EXPORTER_OTLP_PROTOCOL`, dan
   `OBSERVABILITY_HEARTBEAT_INTERVAL`.
4. Panggil `observability.Setup` sebelum HTTP server berjalan. Panggil
   `stack.Shutdown` setelah server berhenti.
5. Gunakan `stack.HandlerWithRoute` untuk `net/http`, atau `otelgin`/`echo-otel`
   plus `stack.LogRequest` untuk Gin/Echo. Jangan memakai URL mentah sebagai
   route.
6. Gunakan `stack.HTTPClient` untuk HTTP keluar dan `otelsql` untuk database.
7. Tambahkan external network `monitoring` jika aplikasi berjalan sebagai
   container di VPS yang sama. Untuk aplikasi remote, jangan menambahkan
   network tersebut; gunakan endpoint OTLP publik.
8. Jalankan `go build ./...`, `go vet ./...`, dan `docker compose config --quiet`.
9. Kirim satu request uji, lalu verifikasi metric, log, trace, dan heartbeat
   mengikuti bagian verifikasi di dokumen integrasi.

Larangan:

- Jangan commit `otel.env` yang berisi credential asli, token, atau password.
- Jangan mengubah package observability pusat dari repo aplikasi.
- Jangan mengubah label `service.name`, `deployment.environment.name`, atau
  `service.version` di tengah jalan.
- Jangan memakai `latest` untuk library atau image.

Laporkan file yang diubah, hasil build, dan hasil verifikasi. Jika ada langkah
yang tidak bisa diuji, sebutkan alasannya.
