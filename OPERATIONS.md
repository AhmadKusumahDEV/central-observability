# Operasi, Kapasitas, dan Batasan

## Model penyimpanan

Semua backend memakai local filesystem dengan Docker named volume. Ini pilihan
yang tepat untuk satu host dan volume rendah-menengah, tetapi bukan cluster
yang highly available.

| Sinyal | Backend | Retensi awal | Lokasi data |
|---|---|---|---|
| Metrics | Prometheus TSDB | 15 hari atau 20 GB, mana yang tercapai lebih dulu | `central-observability-prometheus-data` |
| Logs | Loki + TSDB index | 30 hari | `central-observability-loki-data` |
| Traces | Tempo block storage | 7 hari | `central-observability-tempo-data` |
| Grafana | SQLite | tidak dibatasi umur | `central-observability-grafana-data` |
| Alertmanager | Silences dan notification log | tidak dibatasi umur | `central-observability-alertmanager-data` |

Retensi dikonfigurasi di file, bukan UI:

- Prometheus: flag `--storage.tsdb.retention.time` dan
  `--storage.tsdb.retention.size` di `compose.yaml`.
- Loki: `limits_config.retention_period` di
  `config/loki/loki.yaml`.
- Tempo: `backend_worker.compaction.block_retention` di
  `config/tempo/tempo.yaml`.

Loki dan Tempo belum memiliki hard quota volume. Pantau free disk. Retention
bukan pengganti monitoring kapasitas.

## Estimasi kapasitas

Ukur ingestion nyata selama 24-72 jam sebelum menentukan angka produksi:

```text
kebutuhan_disk =
    GB_masuk_per_hari
  x hari_retensi
  x faktor_replikasi
  x 1.6 sampai 2.0 untuk index, WAL, dan compaction
```

Sisakan minimal 20-30% ruang bebas. Jangan menjalankan Prometheus atau Loki
sampai disk penuh: compaction dan WAL akan gagal dan volume bisa rusak.

Contoh kasar, bukan SLA:

- 50 service, request dan span rendah: mulai 20-50 GB untuk stack gabungan.
- Traffic menengah: metrics lebih cepat tumbuh karena cardinality daripada
  jumlah request. Periksa series count dan `process_resident_memory_bytes`.
- Logs JSON yang besar bisa mendominasi disk. Batasi ukuran body log dan
  hindari mencetak payload penuh.

Prometheus native OTLP receiver didokumentasikan sebagai jalur ingest yang
tidak cocok untuk volume sangat tinggi. Jika kebutuhan tumbuh, pertahankan
Collector sebagai gateway lalu kirim ke Mimir, Thanos, VictoriaMetrics, atau
managed backend.

## Cardinality

Risiko terbesar metrics bukan jumlah request, tetapi kombinasi label. Contoh
label yang tidak boleh berasal dari input pengguna:

- `http.route` berisi ID
- `user.id`, email, tenant, atau sesi
- URL query string mentah
- `trace_id`, `span_id`, request ID, atau message ID
- timestamp sebagai label
- nilai status dinamis yang jumlahnya tak terbatas

Gunakan route template, enum status, dan atribut span/log untuk nilai
high-cardinality. Di Prometheus, cek:

```promql
sum by (__name__) ({__name__=~".+"})
```

dan series aktif:

```http
http://127.0.0.1:9090/api/v1/status/tsdb
```

Di Loki, pertahankan hanya label index rendah cardinality. `trace_id`
disimpan sebagai structured metadata agar query tetap bisa dilakukan tanpa
membuat stream baru.

## Keamanan

Batas yang sudah diterapkan:

- Aplikasi hanya bergabung ke network `monitoring`.
- Backend berada di network `observability` yang terpisah.
- Semua port administrasi dipetakan ke loopback host.
- Collector adalah satu-satunya jalur OTLP dari aplikasi.
- Grafana tidak mengizinkan anonymous access atau sign-up.
- Credential dibaca dari Docker secret atau file, bukan literal Compose.

Yang perlu diperhatikan:

- Basic auth pada Caddy adalah pilihan fallback. TLS wajib dan token perlu
  dirotasi. Basic auth tidak memberikan tenant isolation dan tidak mengikat
  identitas ke `service.name`.
- Siapa pun yang memiliki akses ke network `monitoring` dapat mengirim
  telemetry tanpa auth. Batasi akses Docker ke operator tepercaya.
- Collector tidak menyediakan rate limit bawaan untuk semua skenario. Lindungi
  endpoint publik dengan firewall, IP allowlist, atau proxy yang mendukung rate
  limit.
- Jangan membuka 4317/4318, Prometheus, Loki, Tempo, Alertmanager, atau Grafana
  ke internet tanpa auth dan TLS.
- Log bisa mengandung PII, token, dan payload sensitif. Collector saat ini tidak
  melakukan redaksi otomatis. Tambahkan processor `transform` atau
  redaksi di aplikasi sebelum mengirim log.
- Grafana admin password awal disimpan di secret file. Rotasi setelah
  provisioning pertama bila diperlukan.
- Sertifikat Caddy dan state TLS tersimpan di volume Caddy.
- Gunakan digest image (`@sha256:...`) sebagai hardening setelah tag versi
  lolos pengujian, terutama untuk deployment produksi.

## Backup dan restore

Backup aman menggunakan cold backup: penulis dihentikan sebentar, volume
diarsipkan, lalu service dinyalakan kembali.

```sh
sh scripts/backup.sh
```

Script menghasilkan arsip di `backups/<timestamp>/` dan manifest durasi.
Secret tidak termasuk dalam arsip. Backup secret secara terpisah dengan
enkripsi.

Langkah restore ke stack yang benar-benar bersih:

1. Hentikan stack.
2. Buat ulang named volume yang kosong.
3. Ekstrak arsip ke volume dengan container helper sementara.
4. Pulihkan secret dan file konfigurasi dari repository versi yang sesuai.
5. Jalankan stack dan uji query Prometheus, Loki, Tempo, serta login Grafana.
6. Verifikasi alert rule, dashboard, dan data historis.
7. Jalankan smoke test demo atau kirim satu request aplikasi nyata.

Jangan menyalin file TSDB Loki/Prometheus saat container sedang menulis.
Snapshot native atau storage snapshot lebih baik bila tersedia. Backup harus
diuji restore; keberadaan arsip saja bukan bukti recoverability.

## Alert dan keterbatasan deteksi down

Aturan `ServiceNoHeartbeat` memakai `last_over_time` pada
`target_info` 24 jam dan heartbeat dua menit. Tujuannya adalah tetap
mendeteksi service yang berhenti mengirim setelah series aktif menghilang.
Konsekuensinya:

- Service yang decommission harus di-silence atau repository alert perlu
  dibersihkan; jika tidak, alert dapat tetap muncul sampai jendela lookback
  berakhir.
- Untuk beberapa replika, heartbeat pada level job hanya menandakan salah satu
  replika masih hidup. Gunakan `service.instance.id` stabil dan ubah
  aturan menjadi level instance bila perlu alarm per replika.
- Aplikasi tanpa heartbeat tidak dapat dideteksi down dari data push saja.
  Tambahkan probe eksternal atau heartbeat eksplisit.
- Alert Collector down hanya bekerja bila Prometheus masih dapat scrape
  Collector; jika host mati, alert dari dalam stack juga tidak terkirim.
  Gunakan uptime monitoring eksternal untuk host-level failure.

Notifikasi Telegram menggunakan placeholder sampai token dan chat ID diisi.
Alert firing tetap bisa diverifikasi di UI Alertmanager tanpa mengirim pesan.

## HA dan scaling

Stack ini single-node:

- Collector menyimpan sending queue di memori. Restart Collector dapat
  kehilangan antrean yang belum terkirim.
- Prometheus, Loki, dan Tempo tidak direplikasi.
- Grafana memakai SQLite.
- Alertmanager berjalan satu instance dan tidak memiliki gossip cluster.
- Named volume terikat ke host.

Untuk kebutuhan HA, pindahkan metrics ke remote storage/Mimir, Loki dan Tempo
ke object storage dengan mode terdistribusi, Grafana DB ke PostgreSQL, dan
Collector ke beberapa replika di belakang load balancer. Itu perubahan
arsitektur, bukan sekadar menaikkan `replicas` di Compose.

## Clock

Prometheus, Loki, dan Tempo membandingkan timestamp. Sinkronkan semua host
dengan NTP. Skew waktu dapat membuat data historis tidak muncul atau trace/log
saling terlihat tidak berhubungan.

## Pemeriksaan rutin

Harian:

- Cek Grafana dashboard Stack Health.
- Cek Alertmanager dan notifikasi.
- Cek free disk host.
- Cek error Collector: exporter failures, queue drops, rejected data.

Mingguan:

- Cek pertumbuhan series Prometheus dan volume log/trace.
- Uji backup dengan restore ke environment sementara.
- Cek tag CVE image upstream dan rencanakan upgrade versi.
- Cek credential, token Telegram, dan sertifikat TLS.

Sebelum upgrade:

```sh
docker compose config --quiet
make test-rules
docker compose pull
docker compose up -d
sh scripts/e2e-smoke.sh
```

Uji di stack staging terlebih dahulu untuk perubahan major Prometheus, Loki,
Tempo, atau OTel Collector.

