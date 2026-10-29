# Central Observability

Satu stack observability terpusat untuk banyak aplikasi. Aplikasi mengirim
traces, metrics, dan logs melalui OTLP ke satu Collector. Grafana menjadi UI
utama, Prometheus menyimpan metrics, Loki menyimpan logs, dan Tempo menyimpan
traces.

Mulai dari [docs/README.md](docs/README.md). Untuk memasang aplikasi baru,
ikuti [docs/AGENT-INTEGRATION.md](docs/AGENT-INTEGRATION.md).

## Komponen

| Komponen | Versi | Tugas |
|---|---|---|
| OpenTelemetry Collector Contrib | 0.164.0 | Gateway OTLP dan routing |
| Grafana | 13.3.0 | Dashboard, korelasi, dan Explore |
| Prometheus | 3.15.1 | Metrics, rules, dan alert |
| Loki | 3.6.16 | Logs |
| Tempo | 3.1.1 | Traces |
| Alertmanager | 0.34.1 | Routing notifikasi |
| Caddy, profil opsional | 2.11.7 | TLS dan auth untuk endpoint remote |

Semua image memakai tag versi yang dikunci, bukan `latest`.

## Quick start

Prasyarat: Docker Engine dengan Compose v2.

Linux:

```sh
cp .env.example .env
sh scripts/init.sh
docker compose config --quiet
docker compose up -d
```

Windows PowerShell:

```powershell
Copy-Item .env.example .env
powershell -ExecutionPolicy Bypass -File .\scripts\init.ps1
docker compose config --quiet
docker compose up -d
```

Script init membuat password Grafana, placeholder Telegram, file `.env`,
dan Docker network `monitoring`.

Akses:

- Grafana: http://127.0.0.1:3000
- Prometheus: http://127.0.0.1:9090
- Alertmanager: http://127.0.0.1:9093
- Dashboard: http://127.0.0.1:3000/d/central-service-overview

User Grafana adalah `admin`. Password dibaca dari:

```sh
cat secrets/grafana_admin_password
```

## Endpoint untuk aplikasi

Container aplikasi memakai network eksternal `monitoring`:

```text
http://otel-collector:4317
http://otel-collector:4318
```

Aplikasi tidak memakai endpoint Prometheus, Loki, atau Tempo secara langsung.

## Contoh aplikasi

Tiga contoh lengkap tersedia:

```sh
docker compose -f examples/docker-compose.client.yml --profile go up -d --build
docker compose -f examples/docker-compose.client.yml --profile node up -d --build
docker compose -f examples/docker-compose.client.yml --profile python up -d --build
```

Atau gunakan target Makefile:

```sh
make example-go
make example-node
make example-python
```

Port host: Go `8081`, Node `8082`, Python `8083`. File Compose
client hanya berisi aplikasi dan network; seluruh komponen monitoring berada
di stack pusat.

## Template Go untuk project baru

[templates/go-service](templates/go-service/) adalah project Go kecil yang
sudah mengirim traces, histogram, logs, heartbeat, span HTTP keluar, dan span
database. Yang perlu disalin ke project baru hanya folder
[pkg/observability](templates/go-service/pkg/observability/observability.go).
Jangan menyalin stack monitoring ke project aplikasi.

## Smoke test

Kirim traces, metrics, dan logs langsung ke Collector lalu verifikasi ketiga
backend:

```sh
sh scripts/smoke-test.sh
```

Di Windows, jalankan dari Git Bash. Script memakai image
`telemetrygen:v0.163.0` yang dipin.

## Generator service

Buat file integrasi untuk service yang sudah memiliki image:

```sh
sh scripts/new-service.sh \
  --name chat-api \
  --environment prod \
  --namespace acme \
  --version 1.4.2 \
  --image registry.example.com/chat-api:1.4.2 \
  --output ../chat-api
```

Generator membuat `otel.env`, `docker-compose.yml`, dan
`README.md` tanpa menambahkan stack monitoring ke project aplikasi.

## Dokumentasi

- [Dokumentasi utama](docs/README.md)
- [Panduan agent untuk project baru](docs/AGENT-INTEGRATION.md)
- [Integrasi langkah demi langkah](docs/INTEGRATION.md)
- [Aturan nama dan label](docs/NAMING.md)
- [Troubleshooting](docs/TROUBLESHOOTING.md)
- [Operasi, retensi, backup, dan alert](docs/OPERATIONS.md)
- [Roadmap](docs/ROADMAP.md)
- [Architecture Decision Records](docs/adr/README.md)
- [Kontrak untuk coding agent](AGENTS.md)

## Keamanan

- Backend hanya berada di network internal.
- Port administrasi dipetakan ke loopback host.
- Jangan membuka Collector 4317/4318 atau backend langsung ke internet.
- Untuk server remote, gunakan VPN atau profil Caddy dengan TLS dan
  autentikasi.
- Jangan commit isi folder `secrets/`.

