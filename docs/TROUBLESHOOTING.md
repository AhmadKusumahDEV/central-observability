# Troubleshooting Integrasi

Gunakan daftar ini sebelum mengubah konfigurasi global. Mulai dari aplikasi,
lalu Collector, kemudian backend.

## 1. Data tidak masuk sama sekali

Periksa container stack:

```sh
docker compose ps
curl -fsS http://127.0.0.1:13133/
```

Periksa dari container aplikasi `chat-api`:

```sh
docker compose exec chat-api wget -qO- http://otel-collector:13133/
```

Jika gagal, pastikan:

- aplikasi bergabung ke network eksternal `monitoring`;
- nama network benar;
- Collector sedang berjalan;
- endpoint memakai port 4317 untuk gRPC atau 4318 untuk HTTP.

Lihat log Collector:

```sh
docker compose logs --tail=200 otel-collector
```

Cari pesan `connection refused`, `no such host`, TLS, auth, atau
queue penuh.

## 2. Metrics tidak muncul di Prometheus

Buka http://127.0.0.1:9090 dan jalankan:

```promql
count by (__name__) ({__name__=~"http_server_request_duration.*", service_name="chat-api"})
```

Jika kosong:

1. Untuk package Go ini, pastikan `OBSERVABILITY_ENABLED` bukan `false`.
   Variabel `OTEL_METRICS_EXPORTER` hanya berlaku untuk SDK lain; package
   selalu memakai OTLP.
2. Pastikan histogram direkam dengan nama
   `http.server.request.duration` dan unit `s`.
3. Tunggu satu interval export SDK, biasanya 10 detik.
4. Cek apakah label resource muncul:

```promql
{service_name="chat-api"}
```

Jika metric muncul tetapi dashboard kosong, periksa tempo pemilihan data di
kanan atas Grafana dan pilihan dropdown Service/Environment.

## 3. Label service atau environment hilang

Periksa resource di Prometheus:

```promql
target_info{job=~".*chat-api"}
```

Label environment adalah `deployment_environment_name`. Jika tidak
ada, periksa nilai:

```dotenv
OTEL_RESOURCE_ATTRIBUTES=deployment.environment.name=prod
```

Resource attributes dipisahkan koma tanpa spasi. Nilai `service.name`
berasal dari `OTEL_SERVICE_NAME`. Nama environment tidak boleh
dimasukkan ke nama service.

Jika `service.namespace` diisi, `job` berisi
`namespace/service`. Gunakan label `service_name` untuk filter
di dashboard.

## 4. Logs tidak muncul di Loki

Pastikan exporter logs aktif:

```dotenv
OTEL_LOGS_EXPORTER=otlp
```

Untuk package Go di repo ini, variabel tersebut tidak dibaca; package selalu
mengirim logs lewat OTLP. Variabel ini relevan untuk SDK bahasa lain.

Log yang hanya dicetak ke stdout container tidak otomatis dibaca Collector
pusat. Gunakan OTel Logs SDK atau log bridge yang mengirim OTLP.

Buka Grafana Explore, pilih Loki, dan query:

```logql
{service_name="chat-api", deployment_environment_name="prod"}
```

Jika kosong, periksa log Collector dan pastikan aplikasi memakai endpoint OTLP
yang sama untuk logs.

## 5. Log muncul tetapi trace tidak bisa dibuka

Pastikan log memiliki `trace_id`:

```logql
{service_name="chat-api"} | trace_id=~".+"
```

Jika trace ID kosong, aplikasi menulis log di luar span context. Emit log
setelah span dimulai dan gunakan handler yang meneruskan `context`.

Periksa juga:

- trace sudah masuk ke Tempo;
- regex derived field pada datasource Loki;
- trace ID di log dan di Tempo sama;
- jam host sinkron.

## 6. Trace terputus antar-service

Untuk HTTP, pastikan header W3C `traceparent` dipropagasikan. Untuk
gRPC, gunakan interceptor OTel. Untuk queue, bawa context ke message metadata
dan pulihkan saat consumer memproses.

Di Grafana Tempo, cari trace lalu periksa apakah ada service yang hanya muncul
sebagai root tanpa child span. Service yang tidak meneruskan context akan
membuat trace baru.

## 7. Exemplar tidak terlihat

Prometheus harus dijalankan dengan exemplar storage, dan aplikasi harus
merekam histogram dengan context span yang aktif.

Periksa data Prometheus:

```sh
curl -G http://127.0.0.1:9090/api/v1/query_exemplars \
  --data-urlencode 'query=http_server_request_duration_seconds_bucket{service_name="chat-api"}' \
  --data-urlencode "start=$(date -d '15 minutes ago' +%s)" \
  --data-urlencode "end=$(date +%s)"
```

Jika array `exemplars` kosong, rekam metric di dalam context span.
Datasource Prometheus sudah memiliki tujuan exemplar ke Tempo.

## 8. Remote endpoint ditolak

Periksa:

- Public Hostname `otlp.madgo.my.id` sudah ada di Cloudflare Tunnel;
- endpoint memakai `https://otlp.madgo.my.id`, bukan port 4317/4318;
- protocol SDK `http/protobuf`;
- header Basic auth per aplikasi dikirim;
- karakter `+`, `/`, dan `=` dari base64 sudah diubah menjadi `%2B`, `%2F`,
  dan `%3D`;
- jika Cloudflare Access aktif, service token dikirim sebagai header.

Lihat log Nginx:

```sh
docker logs --tail=200 nginx-proxy
```

Arti status yang umum:

- `401`: Basic auth salah atau user belum ada di `otlp.htpasswd`.
- `403`: Cloudflare Access menolak service token, atau gRPC diaktifkan pada
  hostname yang dilindungi Access.
- `404`: path bukan `/v1/traces`, `/v1/metrics`, atau `/v1/logs`.
- `413`: payload melewati 8 MiB di Nginx atau batas Collector. Perkecil batch.
- `429`: rate limit Nginx. Kurangi laju atau tambah kapasitas.
- `502`: Nginx tidak dapat menjangkau Collector; periksa network
  `observability-ingress` dan container Collector.
- `504` atau `524`: timeout. OTLP normalnya pendek; periksa Collector dan
  Cloudflare 125 detik.

Jangan membuka port Collector langsung ke internet.

## 9. Backend terlihat down

Periksa status:

```sh
docker compose ps
docker compose logs --tail=100 prometheus loki tempo
```

Jika Collector melaporkan retry atau gagal mengirim, periksa DNS internal dan
apakah backend sedang restart. Selesaikan masalah penyimpanan sebelum
menyalakan ulang Collector.

## 10. Data lama tidak muncul

Periksa jam host dengan NTP. Timestamp yang terlalu jauh di masa depan atau
lampaui retensi akan ditolak atau tidak ditampilkan. Periksa juga rentang waktu
dan timezone Grafana.

## Bantuan berikutnya

- [README.md](README.md)
- [INTEGRATION.md](INTEGRATION.md)
- [NAMING.md](NAMING.md)
- [OPERATIONS.md](OPERATIONS.md)

