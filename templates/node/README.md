# Node.js Observability Template

Template ini mengirim traces, metrics, dan logs ke Collector pusat tanpa
mengimpor framework HTTP apa pun. Core ada di `src/observability/`; adapter
Express/Fastify hanya membaca route dan status.

## Yang disalin ke project

```text
src/observability/
src/adapters/          # pilih adapter yang dipakai
instrumentation.js
otel.env.example
```

Jangan salin `src/app.js`; itu hanya server contoh `node:http`. Jangan
menambahkan Grafana, Prometheus, Loki, Tempo, Collector, atau Alertmanager ke
Compose aplikasi.

## Menjalankan contoh

```sh
npm install
cp otel.env.example otel.env
npm start
```

Uji:

```sh
curl http://127.0.0.1:8080/healthz
curl http://127.0.0.1:8080/work
curl http://127.0.0.1:8080/error
```

Ganti `OTEL_SERVICE_NAME`, `deployment.environment.name`, dan versi di
`otel.env`. File `otel.env` diabaikan Git.

## Endpoint

| Lokasi | Endpoint | Protocol |
|---|---|---|
| Docker di VPS yang sama | `http://otel-collector:4317` | `grpc` |
| Native di VPS yang sama | `http://127.0.0.1:4317` | `grpc` |
| Server remote | `https://otlp.madgo.my.id` | `http/protobuf` |

Remote memakai Basic auth per aplikasi di `OTEL_EXPORTER_OTLP_HEADERS`.
Jangan memakai gRPC publik, jangan menaruh credential di repository, dan
jangan memakai URL `/v1/traces` sebagai endpoint dasar.

## Express

```js
const { expressObservability } = require('./src/adapters/express');

app.use(expressObservability());
```

Pasang sebelum route. Adapter membaca `req.route.path` dan `req.baseUrl`; route
baru tersedia setelah routing, jadi adapter membacanya lagi saat response
selesai.

## Fastify

```js
const { registerFastifyObservability } = require('./src/adapters/fastify');

registerFastifyObservability(app);
```

Route diambil dari `request.routeOptions.url`. Untuk framework lain, gunakan
`beginHttpObservation(req, res, { route })` dari `src/observability`.

## Inisialisasi

`instrumentation.js` harus dimuat sebelum aplikasi:

```sh
node --require ./instrumentation.js src/app.js
```

Jangan memasang `HttpInstrumentation` untuk incoming request bersamaan dengan
wrapper ini; hasilnya span server dan histogram ganda. Untuk HTTP client
keluar, instrumentation dapat ditambahkan terpisah dengan incoming dimatikan.

Lihat [APP-AGENT-PROMPT.md](APP-AGENT-PROMPT.md) untuk instruksi handoff ke
agent aplikasi.
