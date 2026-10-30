# Prompt Integrasi PHP

Gunakan setelah folder `src/` dan `bin/heartbeat.php` disalin ke project. Ganti
nilai `<...>` sebelum memberi prompt ini ke agent.

## Kontrak

| Item | Nilai |
|---|---|
| Framework | `<Laravel \| Symfony \| Slim \| CodeIgniter \| plain PHP>` |
| `service.name` | `<nama-service>` |
| Environment | `<dev \| staging \| prod>` |
| Namespace | `<namespace>` |
| Version | `<versi>` |
| Endpoint | `<http://otel-collector:4318 \| http://127.0.0.1:4318 \| https://otlp.madgo.my.id>` |
| Protocol | `<http/protobuf>` |

Jangan menambahkan Grafana, Prometheus, Loki, Tempo, Collector, atau
Alertmanager. Jangan menaruh credential di repository, URL, log, atau chat.

## Prasyarat

```sh
php -v
php -m | findstr /C:"curl" /C:"json" /C:"zlib"
composer install --no-interaction
composer validate --strict
```

PHP minimal 8.3. Versi paket di `composer.json` bersifat tetap; jangan
menggantinya dengan `latest` atau rentang `^` tanpa alasan. Header `findstr`
untuk Windows; di Linux pakai `php -m | grep -E 'curl|json|zlib'`.

## Langkah

1. Salin folder `src/` ke `app/Observability/` atau lokasi lain, lalu daftarkan
   namespace `Observability\\` di `composer.json` dengan PSR-4.
2. Buat `otel.env` dari `otel.env.example`. Isi `OTEL_SERVICE_NAME`,
   `deployment.environment.name`, `service.version`, dan endpoint. Jangan
   commit file itu.
3. Panggil `Telemetry::boot()` satu kali di bootstrap aplikasi, sebelum router
   menangani request.
4. Bungkus handler HTTP dengan `$telemetry->httpServer()->handle($method,
   $routeTemplate, $handler)`, atau pasang `$telemetry->psr15(...)` untuk
   framework PSR-15. Kirim pola route, bukan URL mentah.
5. Bungkus query penting dengan `$telemetry->database($system)->trace(...)`
   dan panggilan keluar dengan `$telemetry->httpClient()->request(...)`.
6. Jalankan `php bin/heartbeat.php` sebagai service terpisah di Compose
   aplikasi, memakai environment variable yang sama.
7. Pastikan tidak ada auto-instrumentation lain yang aktif
   (`OTEL_PHP_AUTOLOAD_ENABLED` harus kosong) supaya span tidak ganda.

## Verifikasi

```sh
composer test
php -l src/Telemetry/Telemetry.php
curl -fsS http://127.0.0.1:8080/healthz
curl -fsS http://127.0.0.1:8080/<route-uji>
```

Tunggu 20-30 detik, lalu cek di Grafana:

```promql
sum by (service_name, deployment_environment_name, http_route) (
  rate(http_server_request_duration_seconds_count{
    service_name="<service>", deployment_environment_name="<env>"
  }[5m])
)
```

```promql
time() - max_over_time(app_heartbeat_timestamp_seconds{
  service_name="<service>", deployment_environment_name="<env>"
}[2m])
```

```logql
{service_name="<service>", deployment_environment_name="<env>"} | trace_id=~".+"
```

```traceql
{ resource.service.name = "<service>" &&
  resource.deployment.environment.name = "<env>" }
```

Terima hanya jika histogram muncul, heartbeat di bawah 30 detik, log punya
trace ID, dan Tempo punya satu server span per request. Jika belum muncul
setelah dua interval export, laporkan `BLOCKED` beserta isi log aplikasi, bukan
`DONE`.
