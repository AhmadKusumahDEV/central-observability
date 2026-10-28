# Roadmap

Dokumen ini memisahkan kapasitas yang sudah tersedia dari pekerjaan berikutnya.

## Sudah tersedia

- Satu gateway OTLP untuk traces, metrics, dan logs.
- Routing ke Tempo, Loki, dan Prometheus.
- Filter service dan environment di Grafana.
- Log-to-trace, trace-to-logs, dan exemplar metrics.
- Alert error rate, latency, heartbeat, Collector down, dan Watchdog.
- Provisioning datasource dan dashboard sebagai kode.
- Contoh Go, Node.js, dan Python.
- External network untuk aplikasi dan network internal untuk backend.
- Optional TLS ingress dengan Basic auth.
- Backup cold, smoke test, dan generator integrasi.

## Prioritas berikutnya

1. Autentikasi per aplikasi dengan bearer token atau mTLS.
2. Rate limit dan validasi atribut di gateway remote.
3. Object storage untuk Tempo dan Loki.
4. Uji restore backup ke stack bersih.
5. Redaksi PII dan secret di log.
6. CI yang menjalankan validasi config dan semua contoh.
7. Dashboard per framework: Go net/http, Express, Flask.
8. Katalog service dan ownership.
9. Recording rules SLO dan error budget.

## Skala dan ketersediaan

Baseline sekarang single-node. Perubahan berikut memerlukan keputusan
arsitektur baru:

- Prometheus remote write ke Mimir atau sistem metrics terdistribusi.
- Loki dan Tempo mode terdistribusi dengan object storage.
- Collector beberapa replika di belakang load balancer.
- Alertmanager cluster.
- Grafana database PostgreSQL dan backup otomatis.

## Belum direncanakan

- Autentikasi user multi-organisasi yang kompleks.
- Menjalankan stack monitoring terpisah di setiap repository aplikasi.
- Menyimpan seluruh payload request/response di logs.
- Menggunakan label Loki untuk nilai high-cardinality.

Lihat [OPERATIONS.md](OPERATIONS.md) untuk batas kapasitas dan
[adr/README.md](adr/README.md) untuk alasan keputusan saat ini.

