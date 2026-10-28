#!/usr/bin/env sh
set -eu

ROOT_DIR=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
cd "$ROOT_DIR"

COLLECTOR_HEALTH=http://127.0.0.1:13133/
PROMETHEUS=http://127.0.0.1:9090
LOKI=http://127.0.0.1:3100
TEMPO=http://127.0.0.1:3200
MONITORING_NETWORK=monitoring
SMOKE_SERVICE=smoke-test
SMOKE_ENVIRONMENT=dev
TELEMETRYGEN_IMAGE=ghcr.io/open-telemetry/opentelemetry-collector-contrib/telemetrygen:v0.163.0
WAIT_SECONDS=60

for command in docker curl; do
    command -v "$command" >/dev/null 2>&1 || {
        echo "FAIL: command '$command' tidak ditemukan" >&2
        exit 1
    }
done

if ! curl -fsS "$COLLECTOR_HEALTH" >/dev/null; then
    echo "FAIL: Collector tidak sehat di $COLLECTOR_HEALTH" >&2
    exit 1
fi

if ! docker network inspect "$MONITORING_NETWORK" >/dev/null 2>&1; then
    echo "FAIL: Docker network '$MONITORING_NETWORK' tidak ada" >&2
    exit 1
fi

run_generator() {
    signal=$1
    count_flag=$2
    count=$3
    generator_log=/tmp/opentelemetry-smoke-$signal.log
    echo "Mengirim $signal..."
    if ! docker run --rm \
        --network "$MONITORING_NETWORK" \
        "$TELEMETRYGEN_IMAGE" \
        "$signal" \
        --otlp-endpoint otel-collector:4317 \
        --otlp-insecure \
        --service "$SMOKE_SERVICE" \
        --otlp-attributes "deployment.environment.name=\"$SMOKE_ENVIRONMENT\"" \
        "$count_flag" "$count" \
        --batch=false >"$generator_log" 2>&1; then
        cat "$generator_log" >&2
        rm -f "$generator_log"
        return 1
    fi
    rm -f "$generator_log"
}

run_generator traces --traces 5
run_generator metrics --metrics 5
run_generator logs --logs 5

wait_for_pattern() {
    name=$1
    url=$2
    pattern=$3
    elapsed=0
    while [ "$elapsed" -lt "$WAIT_SECONDS" ]; do
        body=$(curl -fsS "$url" 2>/dev/null || true)
        if printf '%s' "$body" | grep -Eq "$pattern"; then
            echo "PASS: $name"
            return 0
        fi
        sleep 2
        elapsed=$((elapsed + 2))
    done
    echo "FAIL: $name tidak muncul dalam $WAIT_SECONDS detik" >&2
    return 1
}

prometheus_query=$(printf 'service_name="%s", deployment_environment_name="%s"' "$SMOKE_SERVICE" "$SMOKE_ENVIRONMENT")
prometheus_encoded=$(printf '%s' "$prometheus_query" | sed 's/"/%22/g; s/ /%20/g')
prometheus_url="$PROMETHEUS/api/v1/query?query=%7B$prometheus_encoded%7D"
wait_for_pattern "metrics di Prometheus" "$prometheus_url" "\"service_name\":\"$SMOKE_SERVICE\""

loki_query=$(printf '{service_name="%s", deployment_environment_name="%s"}' "$SMOKE_SERVICE" "$SMOKE_ENVIRONMENT")
loki_encoded=$(printf '%s' "$loki_query" | sed 's/{/%7B/g; s/}/%7D/g; s/"/%22/g; s/=/%3D/g; s/,/%2C/g; s/ /%20/g')
loki_url="$LOKI/loki/api/v1/query_range?limit=5&query=$loki_encoded"
wait_for_pattern "logs di Loki" "$loki_url" "\"values\":\[\["

tempo_query=$(printf '{ resource.service.name="%s" && resource.deployment.environment.name="%s" }' "$SMOKE_SERVICE" "$SMOKE_ENVIRONMENT")
tempo_encoded=$(printf '%s' "$tempo_query" | sed 's/{/%7B/g; s/}/%7D/g; s/"/%22/g; s/ /%20/g; s/=/%3D/g; s/&/%26/g')
tempo_url="$TEMPO/api/search?limit=5&q=$tempo_encoded"
wait_for_pattern "traces di Tempo" "$tempo_url" "\"traceID\""

echo
echo "Smoke test selesai: metrics, logs, dan traces dari service '$SMOKE_SERVICE' diterima stack pusat."

