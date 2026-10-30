# Panduan untuk Coding Agent

Dokumen ini adalah kontrak kerja untuk agent yang mengubah repository
`central-observability`. Baca dokumen ini dan
[docs/README.md](docs/README.md) sebelum mengubah konfigurasi atau menambah
aplikasi.

## Tujuan repository

Repository ini menjalankan satu stack observability terpusat untuk banyak
aplikasi. Aplikasi hanya mengirim OTLP ke Collector. Aplikasi tidak menjalankan
Grafana, Prometheus, Loki, Tempo, Collector, atau Alertmanager sendiri.

## Aturan yang tidak boleh dilanggar

- Jangan menambahkan service monitoring ke Compose aplikasi client.
- Edge publik adalah Cloudflare Tunnel yang sudah ada menuju Nginx internal.
  Jangan mengaktifkan profil Caddy bersamaan dengan Nginx.
- Jangan bind port backend ke `0.0.0.0` atau internet. Backend hanya boleh
  dijangkau lewat network Docker internal. Pengecualian: Collector boleh bind
  ke `127.0.0.1:4317/4318` agar aplikasi native di VPS yang sama dapat mengirim
  telemetry; jangan membukanya ke alamat publik.
- Endpoint remote memakai OTLP/HTTP di Cloudflare Tunnel dan Basic auth per
  aplikasi. OTLP gRPC tetap untuk aplikasi same-host; Cloudflare Access tidak
  melindungi gRPC.
- Aplikasi bergabung ke external network `monitoring`. Backend hanya
  berada di network internal `central-observability-internal`.
- Jangan mengekspos backend ke internet. Hanya Grafana dan OTLP/HTTP yang
  boleh memiliki hostname publik; keduanya masuk lewat Nginx/Tunnel.
- Gunakan image dan library dengan versi tertentu. Jangan memakai
  `latest` atau rentang versi yang dapat berubah diam-diam.
- Semua perubahan provisioning harus berada di file repository. Jangan
  mengandalkan konfigurasi yang hanya diklik di UI.
- Jangan commit secret. Gunakan file di `secrets/` yang sudah diabaikan
  Git.
- Pertahankan kontrak resource `service.name`,
  `deployment.environment.name`, dan `service.version`.
- Setiap aplikasi yang ingin memakai alert service-down harus mengirim
  heartbeat `app.heartbeat.timestamp` setiap 10-15 detik.
- Jangan memasukkan ID pengguna, URL mentah, trace ID, atau nilai tak terbatas
  ke label metrics/Loki.

## Urutan membaca

1. [docs/README.md](docs/README.md) untuk gambaran arsitektur dan alamat.
2. [docs/INTEGRATION.md](docs/INTEGRATION.md) untuk memasang aplikasi.
3. [docs/NAMING.md](docs/NAMING.md) sebelum memilih nama atau label.
4. [docs/TROUBLESHOOTING.md](docs/TROUBLESHOOTING.md) jika data tidak masuk.
5. [docs/OPERATIONS.md](docs/OPERATIONS.md) untuk retensi, backup, upgrade, dan
   alert.
6. [docs/ROADMAP.md](docs/ROADMAP.md) untuk batasan dan rencana berikutnya.
7. [docs/ONLINE-ACCESS.md](docs/ONLINE-ACCESS.md) untuk akses publik, Nginx,
   Cloudflare Tunnel, dan keamanan endpoint remote.

## Peta file

| Path | Tanggung jawab |
|---|---|
| `compose.yaml` | Daftar service pusat, network, port, dan volume |
| `config/otel-collector/` | Penerimaan OTLP dan routing ke backend |
| `config/prometheus/` | Scrape, retensi, alert, dan test alert |
| `config/loki/` | Index labels dan retensi logs |
| `config/tempo/` | Retensi traces dan storage |
| `config/alertmanager/` | Routing notifikasi |
| `grafana/provisioning/` | Datasource yang dibuat otomatis |
| `grafana/dashboards/` | Dashboard JSON sebagai kode |
| `ingress/` | Profil Caddy lama; tidak dipakai saat Nginx menjadi edge |
| `deploy/` | Overlay produksi, config Nginx, dan prosedur akses online |
| `examples/` | Aplikasi contoh dan Compose client |
| `templates/go-service/` | Template Go yang bisa disalin ke project baru |
| `templates/node/` | Template Node.js tanpa ketergantungan framework di kode inti |
| `templates/php/` | Template PHP tanpa ketergantungan framework di kode inti |
| `templates/rust/` | Workspace Rust: crate `telemetry` dan `telemetry-tower` |
| `docs/AGENT-INTEGRATION.md` | Instruksi integrasi untuk agent di repo aplikasi |
| `scripts/` | Inisialisasi, smoke test, generator, dan backup |

## Playbook perubahan

### Aplikasi baru

Ikuti [docs/AGENT-INTEGRATION.md](docs/AGENT-INTEGRATION.md). Aplikasi hanya
bergabung ke network `monitoring` dan mengirim OTLP melalui environment
variable. Package Go yang dipakai adalah
`templates/go-service/pkg/observability`, bukan `go-chat/pkg/observability/stack`.
Jangan menduplikasi stack.

Untuk akses publik, baca [docs/ONLINE-ACCESS.md](docs/ONLINE-ACCESS.md) dan
[deploy/nginx/README.md](deploy/nginx/README.md). Secret Nginx berada di
`nginx/secrets/` di VPS dan tidak boleh masuk repository.

### Alert baru

Tambahkan rule ke `config/prometheus/rules/`, tambahkan test yang
bermakna ke `config/prometheus/tests/`, lalu jalankan
`make test-rules`. Reload Prometheus setelah rule valid.

### Upgrade versi

Ubah tag image atau versi library secara eksplisit, perbarui lockfile, jalankan
semua validator, lalu jalankan smoke test. Uji perubahan major di environment
non-produksi.

### Dashboard baru

Simpan JSON di `grafana/dashboards/` dengan datasource UID yang sudah
ada. Dashboard harus bisa memilih `service_name` dan
`deployment_environment_name` dari data nyata.

## Validasi wajib

```sh
docker compose config --quiet
make test-rules
sh scripts/smoke-test.sh
```

Jika mengubah Caddy lokal atau ingress Nginx:

```sh
docker run --rm -it caddy:2.11.7 caddy validate \
  --config /etc/caddy/Caddyfile --adapter caddyfile
```

Untuk Nginx di VPS:

```sh
docker exec nginx-proxy nginx -t
docker compose -f compose.yaml -f deploy/compose.prod.yml config --quiet
```

## Definisi selesai

- Konfigurasi lolos validator dan JSON/YAML dapat diparse.
- Dokumentasi diperbarui dengan tautan relatif yang benar.
- Secret tidak masuk ke diff Git.
- Bukti verifikasi mencantumkan service dan environment yang diuji.
- Perubahan dipecah menjadi commit berdasarkan satu tujuan yang jelas.

## Batas saat ini

Stack menggunakan satu host, local filesystem, dan satu Collector. HA,
multi-tenant isolation, object storage, serta notifikasi eksternal sungguhan
belum menjadi bagian baseline.

