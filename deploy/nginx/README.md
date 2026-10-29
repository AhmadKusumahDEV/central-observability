# Nginx Ingress untuk Observability

Folder ini berisi konfigurasi Nginx yang dipasang ke proyek
`/home/ahmadkusumahdev/nginx` di VPS. Arsitektur yang sudah berjalan tetap
dipakai:

```text
Internet -> Cloudflare -> cloudflared -> nginx-proxy:8080 -> Grafana/Collector
```

Nginx tidak membuka port ke host. Cloudflare Tunnel adalah satu-satunya jalur
masuk. Jangan mengaktifkan profil Caddy di stack observability saat Nginx
menjadi edge, karena dua ingress akan berebut port publik.

## Hostname

Konfigurasi memakai dua hostname:

| Hostname | Tujuan | Akses |
|---|---|---|
| `observability.madgo.my.id` | Grafana | Cloudflare Access + login Grafana |
| `otlp.madgo.my.id` | OTLP/HTTP Collector | Basic auth per aplikasi + Cloudflare Access Service Auth bila diaktifkan |

Prometheus, Loki, Tempo, dan Alertmanager tidak memiliki hostname publik.
Akses admin gunakan SSH tunnel ke `127.0.0.1` atau Tailscale.

Jika ingin nama lain, ubah `server_name` di `observability.conf` dan
`GRAFANA_ROOT_URL` di `.env` VPS. Jangan membuat satu hostname dengan path
`/grafana`, `/otlp`, dan `/prometheus`; pemisahan hostname membuat cookie,
rate limit, dan kebijakan Access lebih jelas.

## Pemasangan di VPS

Jalankan dari direktori `~/nginx` setelah file ini disalin:

```sh
cp docker-compose.yml docker-compose.yml.bak.$(date +%Y%m%d-%H%M%S)
mkdir -p secrets
chmod 700 secrets
```

Tambahkan dua volume dan satu network ke service `nginx`:

```yaml
    volumes:
      - ./observability.conf:/etc/nginx/conf.d/observability.conf:ro
      - ./secrets:/etc/nginx/secrets:ro

    networks:
      - tunnel-net
      - backend-net
      - frontend-net
      - observability-ingress
```

Tambahkan network eksternal di bagian bawah Compose:

```yaml
networks:
  observability-ingress:
    external: true
    name: observability-ingress
```

Buat network dan validasi konfigurasi:

```sh
docker network create observability-ingress 2>/dev/null || true
docker compose config --quiet
docker compose up -d --no-deps nginx
docker exec nginx-proxy nginx -t
docker exec nginx-proxy nginx -s reload
```

`nginx -t` harus berhasil sebelum reload. Jangan memakai `docker compose down`
untuk memasang perubahan ini karena container aplikasi lain ikut berhenti.

## Membuat kredensial OTLP

Setiap aplikasi remote mendapat user Basic auth sendiri:

```sh
sh add-otlp-client.sh example-go-service
```

Script menulis hash ke `secrets/otlp.htpasswd` dan mencetak env yang perlu
disalin ke project. Jangan memakai satu password untuk semua aplikasi.

File htpasswd harus dapat dibaca proses worker Nginx di dalam container. Script
memberi group GID 101 dan mode 640 jika memungkinkan; jika tidak, file memakai
mode 644. File berisi hash password, bukan password plaintext.

Jika Cloudflare Access Service Auth diaktifkan untuk `otlp.madgo.my.id`, buat
service token per aplikasi di Cloudflare Zero Trust. SDK harus mengirim
`CF-Access-Client-Id` dan `CF-Access-Client-Secret` pada setiap request.
Cloudflare Access tidak mendukung gRPC; karena itu endpoint publik memakai
OTLP/HTTP. gRPC tetap tersedia untuk aplikasi same-host di network Docker.

## Cloudflare Tunnel

Tunnel yang berjalan sekarang dikelola dengan token dan tidak membaca
`cloudflared-config.yml`. Tambahkan Public Hostname di dashboard Cloudflare
Zero Trust:

| Public hostname | Service |
|---|---|
| `observability.madgo.my.id` | `http://nginx-proxy:8080` |
| `otlp.madgo.my.id` | `http://nginx-proxy:8080` |

Jangan membuat Public Hostname untuk `loki`, `tempo`, `prometheus`, atau
`alertmanager`. Jangan mengaktifkan gRPC pada hostname OTLP; gunakan HTTP/protobuf.

## Cloudflare Access

Untuk Grafana, buat Access application `observability.madgo.my.id` dengan
policy Allow berdasarkan email atau IdP. Ini lapisan SSO/MFA; login Grafana
tetap aktif.

Untuk OTLP, buat Access application `otlp.madgo.my.id` dengan policy Service
Auth, lalu buat service token per aplikasi. Uji request tanpa token: harus
401/403, bukan redirect ke halaman login.

Aktifkan Always Use HTTPS, cache bypass untuk kedua hostname, dan nonaktifkan
Rocket Loader/Auto Minify untuk Grafana. Jangan mengaktifkan Bot Fight Mode
pada zona yang menerima OTLP karena dapat menantang SDK.

## Pemeriksaan

Dari dalam container Nginx:

```sh
docker exec nginx-proxy wget -qO- --header='Host: observability.madgo.my.id' http://127.0.0.1:8080/healthz
docker exec nginx-proxy wget -qO- --header='Host: otlp.madgo.my.id' http://127.0.0.1:8080/healthz
```

Keduanya harus mengembalikan `ok`. Uji Grafana:

```sh
docker exec nginx-proxy wget -qO- --header='Host: observability.madgo.my.id' http://127.0.0.1:8080/login
```

Respons harus HTML login Grafana, bukan 404/502. Uji OTLP tanpa kredensial
harus menghasilkan 401; dengan kredensial harus diteruskan ke Collector.

Dokumentasi terkait:

- [AGENT-INTEGRATION.md](../../docs/AGENT-INTEGRATION.md)
- [ONLINE-ACCESS.md](../../docs/ONLINE-ACCESS.md)
- [TROUBLESHOOTING.md](../../docs/TROUBLESHOOTING.md)
