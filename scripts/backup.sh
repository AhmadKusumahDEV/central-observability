#!/usr/bin/env sh
set -eu

ROOT_DIR=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
cd "$ROOT_DIR"

TIMESTAMP=$(date -u +%Y%m%dT%H%M%SZ)
BACKUP_DIR="$ROOT_DIR/backups/$TIMESTAMP"
mkdir -p "$BACKUP_DIR"

RUNNING=0
RUNNING_SERVICES=$(docker compose ps --status running --services)
cleanup() {
    if [ "$RUNNING" -eq 1 ]; then
        if [ -n "$RUNNING_SERVICES" ]; then
            docker compose start $RUNNING_SERVICES >/dev/null 2>&1 || true
        fi
    fi
}
trap cleanup EXIT INT TERM

echo "Stopping writers for a consistent cold backup..."
docker compose stop prometheus loki tempo grafana alertmanager
RUNNING=1
started=$(date +%s)

backup_volume() {
    volume=$1
    name=$2
    docker run --rm \
        --volume "$volume:/source:ro" \
        --volume "$BACKUP_DIR:/backup" \
        alpine:3.23.7 \
        sh -c "tar -C /source -czf /backup/$name.tgz ."
    echo "backed up $volume"
}

backup_volume central-observability-prometheus-data prometheus
backup_volume central-observability-loki-data loki
backup_volume central-observability-tempo-data tempo
backup_volume central-observability-grafana-data grafana
backup_volume central-observability-alertmanager-data alertmanager

if docker volume inspect central-observability-caddy-data >/dev/null 2>&1; then
    backup_volume central-observability-caddy-data caddy
fi

finished=$(date +%s)
{
    echo "created_utc=$TIMESTAMP"
    echo "cold_backup_seconds=$((finished - started))"
    echo "note=Secrets are not included. Back them up separately with encryption."
} > "$BACKUP_DIR/MANIFEST.txt"

echo "Backup completed at $BACKUP_DIR"

