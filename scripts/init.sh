#!/usr/bin/env sh
set -eu

ROOT_DIR=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
cd "$ROOT_DIR"
umask 077
mkdir -p secrets

random_secret() {
    path=$1
    if [ -s "$path" ]; then
        echo "keep existing $path"
        return
    fi
    if command -v openssl >/dev/null 2>&1; then
        openssl rand -base64 32 | tr -d '\n' > "$path"
    else
        head -c 32 /dev/urandom | base64 | tr -d '\n' > "$path"
    fi
    echo "created $path"
}

random_secret secrets/grafana_admin_password

if [ ! -f secrets/telegram_bot_token ]; then
    printf '%s' 'REPLACE_WITH_TELEGRAM_BOT_TOKEN' > secrets/telegram_bot_token
    echo "created secrets/telegram_bot_token placeholder"
fi
if [ ! -f secrets/telegram_chat_id ]; then
    printf '%s' '0' > secrets/telegram_chat_id
    echo "created secrets/telegram_chat_id placeholder"
fi
chmod 600 secrets/grafana_admin_password secrets/telegram_bot_token secrets/telegram_chat_id

if [ ! -f .env ]; then
    cp .env.example .env
    echo "created .env from .env.example"
fi

NETWORK=monitoring
if grep -q '^MONITORING_NETWORK=' .env; then
    NETWORK=$(grep '^MONITORING_NETWORK=' .env | head -n 1 | cut -d= -f2-)
fi

if docker info >/dev/null 2>&1; then
    if docker network inspect "$NETWORK" >/dev/null 2>&1; then
        echo "Docker network $NETWORK already exists"
    else
        docker network create "$NETWORK" >/dev/null
        echo "created Docker network $NETWORK"
    fi
else
    echo "warning: Docker daemon is not running; create network '$NETWORK' before starting the stack." >&2
fi

echo
echo "Grafana admin password:"
echo "  cat secrets/grafana_admin_password"
echo "Replace the Telegram placeholders in secrets/ to enable notifications."

