# Security Policy

Repository ini bersifat publik. Isinya adalah konfigurasi, dokumentasi, dan
template. Nilai produksi seperti password, token, private key, dan kredensial
OTLP tidak boleh masuk repository.

## Data yang tidak boleh di-commit

- `.env` dan `.env.*` yang berisi nilai produksi.
- `secrets/` selain `README.md` dan file `*.example`.
- `otlp-clients/`, `nginx/secrets/`, dan semua file `*.htpasswd`.
- Private key, sertifikat client, token Telegram, token Cloudflare, dan
  password Grafana.

File contoh di repository hanya berisi placeholder. Jika sebuah credential
pernah masuk commit, rotasi credential tersebut. Menghapus file dari commit
terbaru tidak menghapusnya dari sejarah Git.

## Endpoint publik

Hanya Grafana dan OTLP/HTTP yang memiliki hostname publik. Prometheus, Loki,
Tempo, Alertmanager, dan port Collector mentah tetap privat. Detail arsitektur
dan auth ada di [docs/ONLINE-ACCESS.md](docs/ONLINE-ACCESS.md).

## Melaporkan masalah

Gunakan private vulnerability reporting di GitHub untuk masalah keamanan.
Jangan membuka issue publik yang memuat token, password, alamat private, atau
detail eksploitasi yang belum diperbaiki.
