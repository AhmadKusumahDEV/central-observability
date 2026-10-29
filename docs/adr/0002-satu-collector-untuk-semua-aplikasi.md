# ADR 0002: Satu Collector untuk Semua Aplikasi

## Status

Diterima.

## Konteks

Setiap aplikasi dapat menjalankan Collector lokal, atau semua aplikasi dapat
mengirim ke satu Collector gateway di stack pusat.

## Keputusan

Gunakan satu Collector gateway untuk host yang sama. Aplikasi cukup bergabung
ke network `monitoring` dan mengirim OTLP ke service
`otel-collector`.

## Alasan

- Retry queue, memory limiter, dan batching hanya dikelola di satu tempat.
- Endpoint aplikasi sederhana dan tidak berubah saat backend berubah.
- Pipeline traces, metrics, dan logs dapat diperiksa dengan satu set log.
- Menghindari stack monitoring kecil di setiap project.

## Konsekuensi

- Collector menjadi titik kritis. Restart Collector dapat membuang queue yang
  masih berada di memori.
- Kebutuhan throughput tinggi memerlukan replica atau pembagian gateway.
- Health endpoint dan dashboard Collector perlu dipantau.

## Alternatif yang ditolak

- Collector agent per aplikasi: menambah overhead operasional sebelum throughput
  benar-benar membutuhkannya.
- Aplikasi mengirim langsung ke semua backend: menyebarkan kredensial dan
  kebijakan retry.

Referensi: [Collector deployment](https://opentelemetry.io/docs/collector/deploy/).

