# ADR 0001: OTLP sebagai Kontrak Telemetry Utama

## Status

Diterima.

## Konteks

Aplikasi dapat ditulis dalam Go, Node.js, Python, atau bahasa lain. Stack harus
menerima traces, metrics, dan logs tanpa membuat format khusus per bahasa.

## Keputusan

Semua aplikasi mengirim data melalui OpenTelemetry Protocol (OTLP). Aplikasi
tidak menulis langsung ke Prometheus, Loki, atau Tempo.

## Alasan

- OTLP adalah protokol terbuka dan didukung SDK lintas bahasa.
- Collector dapat melakukan batching, retry, routing, dan validasi.
- Aplikasi tidak perlu mengetahui endpoint backend.
- Kontrak resource `service.name` dan
  `deployment.environment.name` konsisten untuk ketiga sinyal.
- Perubahan backend tidak memaksa perubahan konfigurasi di semua aplikasi.

## Konsekuensi

- Aplikasi harus mengaktifkan exporter OTLP.
- Tim perlu memahami batas cardinality label.
- Logs hanya masuk jika aplikasi mengirim LogRecord OTLP; stdout mentah tidak
  dibaca oleh Collector pusat.

## Alternatif yang ditolak

- Scraping Prometheus langsung dari setiap aplikasi: cocok untuk metrics, tetapi
  tidak menyatukan traces dan logs.
- Mengirim langsung ke Loki/Tempo/Prometheus: mengekspos backend dan menggandakan
  konfigurasi retry di setiap aplikasi.

Referensi: [OpenTelemetry Protocol](https://opentelemetry.io/docs/specs/otlp/).

