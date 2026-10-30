'use strict';

const { NodeSDK } = require('@opentelemetry/sdk-node');
const {
  OTLPTraceExporter: GrpcTraceExporter,
} = require('@opentelemetry/exporter-trace-otlp-grpc');
const {
  OTLPMetricExporter: GrpcMetricExporter,
} = require('@opentelemetry/exporter-metrics-otlp-grpc');
const {
  OTLPLogExporter: GrpcLogExporter,
} = require('@opentelemetry/exporter-logs-otlp-grpc');
const {
  OTLPTraceExporter: HttpTraceExporter,
} = require('@opentelemetry/exporter-trace-otlp-proto');
const {
  OTLPMetricExporter: HttpMetricExporter,
} = require('@opentelemetry/exporter-metrics-otlp-proto');
const {
  OTLPLogExporter: HttpLogExporter,
} = require('@opentelemetry/exporter-logs-otlp-proto');
const { PeriodicExportingMetricReader } = require('@opentelemetry/sdk-metrics');
const { BatchLogRecordProcessor } = require('@opentelemetry/sdk-logs');
const {
  defaultResource,
  resourceFromAttributes,
} = require('@opentelemetry/resources');
const { loadConfig } = require('./config');
const { createRuntime, setRuntime, clearRuntime } = require('./runtime');

let sdk;

function createSdk(config) {
  const common = {
    url: config.endpoint,
    timeoutMillis: config.timeoutMillis,
  };

  const exporters = config.protocol === 'grpc'
    ? {
        trace: new GrpcTraceExporter(common),
        metric: new GrpcMetricExporter(common),
        log: new GrpcLogExporter(common),
      }
    : {
        trace: new HttpTraceExporter({
          ...common,
          headers: config.headers,
          compression: config.compression,
        }),
        metric: new HttpMetricExporter({
          ...common,
          headers: config.headers,
          compression: config.compression,
        }),
        log: new HttpLogExporter({
          ...common,
          headers: config.headers,
          compression: config.compression,
        }),
      };

  const resource = defaultResource().merge(resourceFromAttributes(config.attributes));

  return new NodeSDK({
    resource,
    traceExporter: exporters.trace,
    metricReaders: [
      new PeriodicExportingMetricReader({
        exporter: exporters.metric,
        exportIntervalMillis: config.metricIntervalMillis,
        exportTimeoutMillis: Math.min(config.timeoutMillis, config.metricIntervalMillis),
      }),
    ],
    logRecordProcessors: [
      new BatchLogRecordProcessor({ exporter: exporters.log }),
    ],
    instrumentations: [],
  });
}

function start(env = process.env) {
  if (sdk) return;
  const config = loadConfig(env);
  const next = createSdk(config);
  next.start();
  sdk = next;
  setRuntime(createRuntime(config));
}

async function shutdown() {
  if (!sdk) return;
  const current = sdk;
  sdk = undefined;
  try {
    await current.shutdown();
  } finally {
    clearRuntime();
  }
}

function isStarted() {
  return sdk !== undefined;
}

module.exports = { start, shutdown, isStarted };
