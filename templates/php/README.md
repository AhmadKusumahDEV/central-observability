# Template Observability PHP

Template ini mengirim traces, metrics (termasuk histogram), dan logs ke
Collector pusat. Kode inti hanya memakai paket OpenTelemetry dan PSR; tidak ada
`use` ke framework tertentu. Dukungan framework ditambahkan lewat middleware
PSR-15 atau satu adaptor kecil.

## Yang disalin ke project

```text
src/Config/
src/Telemetry/
src/Http/
src/Logs/
src/Metrics/
src/Database/
src/Propagation/
bin/heartbeat.php
otel.env.example
```

Jangan menyalin `examples/plain`; itu aplikasi contoh. Jangan menambahkan
Grafana, Prometheus, Loki, Tempo, Collector, atau Alertmanager ke Compose
aplikasi. Stack monitoring sudah terpusat.

## Menjalankan contoh

```sh
composer install
php -S 127.0.0.1:8080 -t examples/plain/public
```

Lalu panggil:

```sh
curl -s http://127.0.0.1:8080/healthz
curl -s http://127.0.0.1:8080/work
curl -s http://127.0.0.1:8080/slow
curl -s http://127.0.0.1:8080/outbound
curl -s -o /dev/null -w '%{http_code}' http://127.0.0.1:8080/error
```

Isi environment variable dari `otel.env.example`. File `otel.env` diabaikan
Git.

## Endpoint

| Lokasi | Endpoint | Protocol |
|---|---|---|
| Docker di VPS yang sama | `http://otel-collector:4318` | `http/protobuf` |
| Native di VPS yang sama | `http://127.0.0.1:4318` | `http/protobuf` |
| Server remote | `https://otlp.madgo.my.id` | `http/protobuf` |

PHP memakai OTLP/HTTP supaya tidak perlu ekstensi `grpc`. Jalur gRPC tersedia
kalau ekstensi `grpc` dan paket `open-telemetry/transport-grpc` dipasang, tapi
bukan syarat. Remote memakai Basic auth per aplikasi di
`OTEL_EXPORTER_OTLP_HEADERS`. Jangan memakai URL `/v1/traces` sebagai endpoint
dasar; template menambahkan path per sinyal sendiri.

## Pemakaian paling singkat

```php
<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Observability\Telemetry\Telemetry;

$telemetry = Telemetry::boot();
$http = $telemetry->httpServer();
$logger = $telemetry->logger();

$http->handle($_SERVER['REQUEST_METHOD'], '/orders/{id}', function () use ($logger): void {
    $logger->info('membaca order', ['order_id' => 7]);
    http_response_code(200);
    echo json_encode(['ok' => true]);
});
```

Route yang dikirim ke `handle()` harus template, misalnya `/orders/{id}`,
bukan `/orders/123`. Route mentah membuat jumlah label metrics meledak.

## PSR-15

Slim, Mezzio, Laminas, dan framework lain yang memakai PSR-15 cukup memasang:

```php
use Observability\Http\RouteTemplateResolver;

$app->add($telemetry->psr15(new RouteTemplateResolver(
    static fn ($request): ?string => $request->getAttribute('route_pattern'),
)));
```

Isi resolver dengan cara framework Anda menyimpan pola route. Bila tidak ada,
kembalikan `null` supaya label menjadi `unmatched`, bukan URL asli.

## Database dan HTTP keluar

```php
$orders = $telemetry->database('postgresql')->trace(
    'SELECT',
    'orders',
    static fn () => $pdo->query('SELECT * FROM orders WHERE id = 7')->fetchAll(),
    'shop',
);

$response = $telemetry->httpClient()->request(
    'POST',
    'https://api.contoh.id/v1/charge',
    '/v1/charge',
    [CURLOPT_POSTFIELDS => json_encode(['amount' => 1000])],
);
```

`traceparent` ditambahkan otomatis ke request keluar. Jangan menyimpan query
SQL mentah sebagai atribut karena bisa memuat data pribadi.

## Heartbeat

Alert service down memakai gauge `app.heartbeat.timestamp`. PHP-FPM membuat
proses per request, jadi gauge itu tidak bisa diandalkan dari middleware.
Jalankan proses kecil yang hidup terus:

```sh
php bin/heartbeat.php
```

Jalankan sebagai service terpisah di Compose aplikasi, memakai image dan
environment variable yang sama dengan aplikasi. Proses ini menulis satu log
saat mulai dan berhenti, lalu mengirim heartbeat setiap 10-15 detik.

## Catatan PHP-FPM

Pengiriman data terjadi saat `Telemetry::flush()` di akhir request. Panggil
`flush()` setelah response terkirim, misalnya di `register_shutdown_function`
yang sudah dipasang `Telemetry::boot()`. Jangan memanggil
`fastcgi_finish_request()` sebelum response selesai ditulis.

Jangan mengaktifkan `OTEL_PHP_AUTOLOAD_ENABLED=true` atau auto-instrumentation
framework bersamaan dengan template ini; span server dan histogram akan ganda.

## Verifikasi yang sudah dijalankan

- `composer validate --strict` lolos (hanya peringatan karena versi di-pin).
- `php -l` lolos untuk semua file di `src/`, `bin/`, dan `examples/`.
- `composer test` mengirim traces, metrics, dan logs ke transport palsu di
  memori, lalu memeriksa nama span, atribut resource, histogram, gauge
  heartbeat, dan trace ID di log. Semua pemeriksaan lolos.

Pengiriman HTTP sungguhan ke Collector belum diuji dari mesin ini karena
socket loopback diblokir lingkungan kerja. Uji di server dengan langkah di
[../../docs/INTEGRATION.md](../../docs/INTEGRATION.md).

Lihat [APP-AGENT-PROMPT.md](APP-AGENT-PROMPT.md) untuk instruksi handoff ke
agent di project aplikasi.
