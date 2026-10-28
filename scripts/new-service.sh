#!/usr/bin/env sh
set -eu

usage() {
    cat <<'USAGE'
Usage:
  scripts/new-service.sh \
    --name chat-api \
    --environment prod \
    --namespace acme \
    --version 1.4.2 \
    --image registry.example.com/chat-api:1.4.2 \
    --output ../chat-api

Options:
  --name         Service name. Lowercase letters, numbers, and hyphens.
  --environment  dev, staging, or prod.
  --namespace    Optional service namespace.
  --version      Application version or commit SHA.
  --image        Immutable application image with an explicit tag.
  --output       Directory for otel.env, docker-compose.yml, and README.md.
  --help         Show this help.
USAGE
}

NAME=
ENVIRONMENT=
NAMESPACE=
VERSION=
IMAGE=
OUTPUT=

while [ $# -gt 0 ]; do
    case "$1" in
        --name) NAME=$2; shift 2 ;;
        --environment) ENVIRONMENT=$2; shift 2 ;;
        --namespace) NAMESPACE=$2; shift 2 ;;
        --version) VERSION=$2; shift 2 ;;
        --image) IMAGE=$2; shift 2 ;;
        --output) OUTPUT=$2; shift 2 ;;
        --help) usage; exit 0 ;;
        *) echo "Unknown option: $1" >&2; usage >&2; exit 2 ;;
    esac
done

if [ -z "$NAME" ] || [ -z "$ENVIRONMENT" ] || [ -z "$VERSION" ] || [ -z "$IMAGE" ] || [ -z "$OUTPUT" ]; then
    usage >&2
    exit 2
fi

printf '%s' "$NAME" | grep -Eq '^[a-z][a-z0-9-]{2,62}$' || {
    echo "Invalid service name. Use lowercase letters, numbers, and hyphens (3-63 characters)." >&2
    exit 2
}

case "$ENVIRONMENT" in
    dev|staging|prod) ;;
    *) echo "Invalid environment. Use dev, staging, or prod." >&2; exit 2 ;;
esac

if [ -n "$NAMESPACE" ]; then
    printf '%s' "$NAMESPACE" | grep -Eq '^[a-z][a-z0-9-]{1,62}$' || {
        echo "Invalid namespace. Use lowercase letters, numbers, and hyphens." >&2
        exit 2
    }
fi

if [ -e "$OUTPUT/otel.env" ] || [ -e "$OUTPUT/docker-compose.yml" ]; then
    echo "Output already contains otel.env or docker-compose.yml: $OUTPUT" >&2
    exit 1
fi

if [ -n "$NAMESPACE" ]; then
    RESOURCE_ATTRIBUTES="deployment.environment.name=$ENVIRONMENT,service.namespace=$NAMESPACE,service.version=$VERSION"
else
    RESOURCE_ATTRIBUTES="deployment.environment.name=$ENVIRONMENT,service.version=$VERSION"
fi

mkdir -p "$OUTPUT"

cat > "$OUTPUT/otel.env" <<EOF
OTEL_EXPORTER_OTLP_ENDPOINT=http://otel-collector:4317
OTEL_EXPORTER_OTLP_PROTOCOL=grpc
OTEL_TRACES_EXPORTER=otlp
OTEL_METRICS_EXPORTER=otlp
OTEL_LOGS_EXPORTER=otlp
OTEL_SERVICE_NAME=$NAME
OTEL_RESOURCE_ATTRIBUTES=$RESOURCE_ATTRIBUTES
OTEL_PROPAGATORS=tracecontext,baggage
OTEL_EXPORTER_OTLP_COMPRESSION=gzip
OTEL_EXPORTER_OTLP_TIMEOUT=10000
EOF

cat > "$OUTPUT/docker-compose.yml" <<EOF
name: $NAME

services:
  $NAME:
    image: $IMAGE
    restart: unless-stopped
    env_file:
      - otel.env
    networks:
      - monitoring

networks:
  monitoring:
    external: true
    name: monitoring
EOF

cat > "$OUTPUT/README.md" <<EOF
# $NAME Monitoring Integration

This directory contains only the application settings needed by the central
observability stack. It does not include Prometheus, Loki, Tempo, Grafana,
Collector, or Alertmanager.

Start the application:

    docker compose up -d

The container must reach otel-collector:4317 on the external monitoring
network. Follow docs/INTEGRATION.md in the observability repository for verification,
database instrumentation, and alert ownership.

Service: $NAME

Environment: $ENVIRONMENT

Version: $VERSION
EOF

echo "Created integration files in $OUTPUT"
echo "Files: otel.env, docker-compose.yml, README.md"

