# ADR 0003: Network Monitoring dan Batas Backend

## Status

Diterima.

## Konteks

Aplikasi membutuhkan akses ke Collector, tetapi tidak boleh menjangkau Grafana,
Prometheus, Loki, Tempo, atau Alertmanager.

## Keputusan

Gunakan dua network:

- `monitoring`: external network bersama untuk aplikasi dan Collector.
- `central-observability-internal`: network backend yang hanya berisi
  Collector, Grafana, Prometheus, Loki, Tempo, dan Alertmanager.

Collector adalah satu-satunya container yang mengikuti kedua network.

## Alasan

- Aplikasi tidak dapat mengakses API admin backend.
- Backend tidak perlu dipublikasikan ke network aplikasi.
- Batas akses dapat diperiksa dengan konfigurasi Compose.
- Port admin host cukup dipetakan ke loopback.

## Konsekuensi

- Aplikasi harus bergabung ke network eksternal paling sedikit satu kali.
- Network harus dibuat sebelum stack dijalankan.
- Remote ingestion memerlukan ingress terpisah dengan TLS dan autentikasi.

## Alternatif yang ditolak

- Satu network untuk semua service: aplikasi dapat menjangkau backend secara
  langsung.
- Tanpa network eksternal dan menggunakan host port Collector: lebih sulit
  membatasi akses dan menambah aplikasi baru.

Referensi: [Docker Compose networking](https://docs.docker.com/compose/how-tos/networking/).

