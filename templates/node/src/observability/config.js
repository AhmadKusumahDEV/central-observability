'use strict';

function parsePairs(raw = '') {
  const result = {};
  for (const part of raw.split(',')) {
    const item = part.trim();
    if (!item) continue;
    const separator = item.indexOf('=');
    if (separator <= 0) {
      throw new Error('format daftar key=value tidak valid');
    }
    const key = decodeURIComponent(item.slice(0, separator).trim());
    const value = decodeURIComponent(item.slice(separator + 1).trim());
    result[key] = value;
  }
  return result;
}

function loadConfig(env = process.env) {
  const attributes = parsePairs(env.OTEL_RESOURCE_ATTRIBUTES);
  const serviceName = env.OTEL_SERVICE_NAME || attributes['service.name'];
  const environment = env.DEPLOYMENT_ENVIRONMENT || attributes['deployment.environment.name'];
  const serviceVersion = env.SERVICE_VERSION || attributes['service.version'];

  if (!/^[a-z0-9][a-z0-9-]{2,62}$/.test(serviceName || '')) {
    throw new Error('service.name harus huruf kecil, angka, tanda hubung, 3-63 karakter');
  }
  if (!['dev', 'staging', 'prod'].includes(environment)) {
    throw new Error('deployment.environment.name harus dev, staging, atau prod');
  }
  if (!serviceVersion) {
    throw new Error('service.version wajib diisi');
  }

  const protocol = (env.OTEL_EXPORTER_OTLP_PROTOCOL || 'grpc').toLowerCase();
  if (!['grpc', 'http/protobuf'].includes(protocol)) {
    throw new Error('protocol harus grpc atau http/protobuf');
  }

  const endpoint = (env.OTEL_EXPORTER_OTLP_ENDPOINT ||
    (protocol === 'grpc' ? 'http://otel-collector:4317' : 'https://otlp.madgo.my.id')).replace(/\/+$/, '');
  const parsed = new URL(endpoint);
  if (protocol === 'grpc' && parsed.pathname !== '/') {
    throw new Error('endpoint gRPC tidak boleh memiliki path');
  }
  if (protocol === 'http/protobuf' && /\/v1\/(traces|metrics|logs)$/.test(parsed.pathname)) {
    throw new Error('pakai base URL, jangan /v1/traces atau /v1/metrics');
  }

  const metricIntervalMillis = Number(env.OTEL_METRIC_EXPORT_INTERVAL || 10000);
  if (!Number.isFinite(metricIntervalMillis) || metricIntervalMillis < 5000) {
    throw new Error('OTEL_METRIC_EXPORT_INTERVAL minimal 5000 ms');
  }
  if (metricIntervalMillis > 15000) {
    throw new Error('OTEL_METRIC_EXPORT_INTERVAL maksimal 15000 ms agar heartbeat tepat waktu');
  }

  return {
    serviceName,
    environment,
    serviceVersion,
    attributes: {
      ...attributes,
      'service.name': serviceName,
      'deployment.environment.name': environment,
      'service.version': serviceVersion,
    },
    endpoint,
    protocol,
    headers: parsePairs(env.OTEL_EXPORTER_OTLP_HEADERS),
    compression: env.OTEL_EXPORTER_OTLP_COMPRESSION || 'gzip',
    timeoutMillis: Number(env.OTEL_EXPORTER_OTLP_TIMEOUT || 10000),
    metricIntervalMillis,
  };
}

module.exports = { loadConfig, parsePairs };
