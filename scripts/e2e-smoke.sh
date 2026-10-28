#!/usr/bin/env sh
set -eu

ROOT_DIR=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
cd "$ROOT_DIR"

PROMETHEUS=http://127.0.0.1:9090
GRAFANA=http://127.0.0.1:3000
LOKI=http://127.0.0.1:3100
TEMPO=http://127.0.0.1:3200
DEMO=http://127.0.0.1:8081

fail() {
    echo "FAIL: $1" >&2
    exit 1
}

wait_http() {
    name=$1
    url=$2
    i=0
    until curl -fsS "$url" >/dev/null 2>&1; do
        i=$((i + 1))
        [ "$i" -lt 40 ] || fail "$name did not become ready"
        sleep 2
    done
}

echo "Starting the stack and Go example..."
docker compose up -d --remove-orphans
docker compose -f examples/docker-compose.client.yml --profile go up -d --build

echo "Waiting for services..."
wait_http Grafana "$GRAFANA/api/health"
wait_http Prometheus "$PROMETHEUS/-/ready"
wait_http Tempo "$TEMPO/ready"
wait_http Loki "$LOKI/ready"
wait_http Alertmanager "http://127.0.0.1:9093/-/ready"
wait_http "Demo app" "$DEMO/healthz"

echo "Generating requests..."
i=1
while [ "$i" -le 15 ]; do
    curl -fsS "$DEMO/work" >/dev/null
    curl -sS -o /dev/null "$DEMO/error" || true
    curl -fsS "$DEMO/slow?delay_ms=700" >/dev/null
    i=$((i + 1))
done

echo "Waiting for OTLP batching and backend ingestion..."
sleep 30

prom_json=$(curl -fsS -G "$PROMETHEUS/api/v1/query" \
    --data-urlencode 'query=http_server_request_duration_seconds_count{service_name="example-go", deployment_environment_name="dev"}')
echo "$prom_json" | grep -q '"service_name":"example-go"' || fail "Go example metrics did not reach Prometheus"
echo "PASS: metrics reached Prometheus"

loki_json=$(curl -fsS -G "$LOKI/loki/api/v1/query_range" \
    --data-urlencode 'query={service_name="example-go", deployment_environment_name="dev"}' \
    --data-urlencode 'limit=10')
echo "$loki_json" | grep -q '"values":\[\[' || fail "demo logs did not reach Loki"
echo "PASS: logs reached Loki"

tempo_json=$(curl -fsS -G "$TEMPO/api/search" \
    --data-urlencode 'q={ resource.service.name="example-go" }' \
    --data-urlencode 'limit=10')
echo "$tempo_json" | grep -q '"traceID"' || fail "demo traces did not reach Tempo"
echo "PASS: traces reached Tempo"

rules_json=$(curl -fsS "$PROMETHEUS/api/v1/rules")
echo "$rules_json" | grep -q 'ServiceHighErrorRate' || fail "Prometheus alert rules were not loaded"
echo "$rules_json" | grep -q 'ServiceNoHeartbeat' || fail "heartbeat alert rule was not loaded"
echo "PASS: alert rules loaded"

password=$(cat secrets/grafana_admin_password)
dashboard_json=$(curl -fsS -u "admin:$password" "$GRAFANA/api/search?query=Service%20Overview")
echo "$dashboard_json" | grep -q 'central-service-overview' || fail "provisioned dashboard was not found"
echo "PASS: Grafana dashboard provisioned"

echo
echo "All automated smoke checks passed."
echo "Open $GRAFANA/d/central-service-overview and set Service=example-go, Environment=dev."
echo "Click TraceID in the recent error logs panel to verify log-to-trace correlation."

