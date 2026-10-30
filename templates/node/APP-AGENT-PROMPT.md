# Prompt Integrasi Node.js

Gunakan setelah folder `src/observability`, `src/adapters`, dan
`instrumentation.js` disalin ke project. Ganti nilai `<...>` sebelum memberi
prompt ini ke agent.

## Kontrak

| Item | Nilai |
|---|---|
| Framework | `<Express \| Fastify \| Koa \| node:http>` |
| `service.name` | `<nama-service>` |
| Environment | `<dev \| staging \| prod>` |
| Namespace | `<namespace>` |
| Version | `<versi>` |
| Endpoint | `<http://otel-collector:4317 \| http://127.0.0.1:4317 \| https://otlp.madgo.my.id>` |
| Protocol | `<grpc \| http/protobuf>` |

Jangan menambahkan Grafana, Prometheus, Loki, Tempo, Collector, atau
Alertmanager. Jangan menaruh credential di repository, URL, log, atau chat.

## Prasyarat

```sh
node --version
npm ci
npm ls --depth=0
```

Node minimal `22.15`; image template memakai Node `24.22.1`. Pin dependency
sesuai `package-lock.json`; jangan memakai `latest`.

## Langkah

1. Muat `instrumentation.js` sebelum aplikasi, misalnya
   `node --require ./instrumentation.js src/app.js`.
2. Buat `otel.env` dari `otel.env.example`; isi service, environment, versi,
   endpoint, protocol, dan header remote. Jangan commit `otel.env`.
3. Pasang satu adapter:
   - Express: `app.use(expressObservability())` sebelum route.
   - Fastify: `registerFastifyObservability(app)` sebelum route.
   - `node:http`: `beginHttpObservation(req, res, { route })`; route harus
     template.
4. Jangan memasang `HttpInstrumentation` untuk incoming request bersamaan.
   Untuk HTTP keluar, gunakan instrumentation dengan incoming dimatikan.
5. Pastikan histogram `http.server.request.duration` memakai unit `s` dan
   atribut method, route, status. Route template, bukan URL mentah.
6. Pastikan observable gauge `app.heartbeat.timestamp` mengirim Unix detik dan
   interval export metrics 10-15 detik.
7. Emit log dengan context request; harus ada `trace_id`/`span_id`.
8. Shutdown: hentikan server, tutup koneksi idle, lalu `shutdown()` SDK.

## Verifikasi

```sh
npm run check
docker compose config --quiet
curl -fsS http://127.0.0.1:8080/healthz
curl -fsS http://127.0.0.1:8080/<route-uji>
```

Tunggu 20-30 detik, lalu cek:

```promql
count by (service_name, deployment_environment_name, http_route, http_response_status_code) (
  http_server_request_duration_seconds_count{
    service_name="<service>", deployment_environment_name="<env>"
  }
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

Terima hanya jika histogram muncul, heartbeat < 30 detik, log punya trace ID,
Tempo punya satu server span per request, dan tidak ada span/log ganda. Jika
data belum muncul setelah dua interval export, statusnya `BLOCKED`, bukan
`DONE`.
