#!/usr/bin/env sh
set -eu

# Usage:
#   sh deploy/nginx/add-otlp-client.sh example-go-service
#   sh deploy/nginx/add-otlp-client.sh example-go-service 'password-yang-dipilih'
#
# OTLP_HTPASSWD can override the target file. The default path is the mount
# used by the VPS Nginx container.

NAME=${1:-}
PASSWORD=${2:-}
OTLP_HTPASSWD=${OTLP_HTPASSWD:-/home/ahmadkusumahdev/nginx/secrets/otlp.htpasswd}

if [ -z "$NAME" ]; then
    echo "usage: $0 <service-name> [password]" >&2
    exit 2
fi

case "$NAME" in
    *[!a-z0-9-]*|'')
        echo "service-name harus huruf kecil, angka, dan tanda hubung" >&2
        exit 2
        ;;
esac

if [ -z "$PASSWORD" ]; then
    PASSWORD=$(openssl rand -base64 24 | tr -d '\n')
fi

umask 077
mkdir -p "$(dirname "$OTLP_HTPASSWD")"

if [ -f "$OTLP_HTPASSWD" ] && grep -q "^${NAME}:" "$OTLP_HTPASSWD"; then
    echo "FAIL: user '$NAME' sudah ada di $OTLP_HTPASSWD" >&2
    exit 1
fi

HASH=$(openssl passwd -apr1 "$PASSWORD")
printf '%s:%s\n' "$NAME" "$HASH" >> "$OTLP_HTPASSWD"
chmod 755 "$(dirname "$OTLP_HTPASSWD")"
if chgrp 101 "$OTLP_HTPASSWD" 2>/dev/null; then
    chmod 640 "$OTLP_HTPASSWD"
else
    # Nginx worker di image nginx:alpine memakai UID/GID berbeda dari user VPS.
    # File ini berisi hash, bukan password plaintext; 644 diperlukan agar worker
    # dapat membaca auth_basic_user_file pada bind mount.
    chmod 644 "$OTLP_HTPASSWD"
fi

BASIC=$(printf '%s:%s' "$NAME" "$PASSWORD" | base64 | tr -d '\n')
ENCODED=$(python3 -c 'import sys, urllib.parse; print(urllib.parse.quote(sys.argv[1], safe=""))' "$BASIC")

cat <<EOF
User OTLP dibuat: $NAME
Password: $PASSWORD

Tambahkan ke otel.env project tersebut:
OTEL_EXPORTER_OTLP_ENDPOINT=https://otlp.madgo.my.id
OTEL_EXPORTER_OTLP_PROTOCOL=http/protobuf
OTEL_EXPORTER_OTLP_HEADERS=Authorization=Basic%20$ENCODED

Jika hostname OTLP juga dilindungi Cloudflare Access Service Auth, tambahkan
header service token ke nilai OTEL_EXPORTER_OTLP_HEADERS dengan format:
  CF-Access-Client-Id=<id>,CF-Access-Client-Secret=<secret>

Simpan password ini di secret manager. File htpasswd tidak boleh di-commit.
EOF
