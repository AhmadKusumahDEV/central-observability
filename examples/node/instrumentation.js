'use strict';

const { NodeSDK } = require('@opentelemetry/sdk-node');
const { OTLPTraceExporter } = require('@opentelemetry/exporter-trace-otlp-grpc');
const { OTLPMetricExporter } = require('@opentelemetry/exporter-metrics-otlp-grpc');
const { OTLPLogExporter } = require('@opentelemetry/exporter-logs-otlp-grpc');
const { PeriodicExportingMetricReader } = require('@opentelemetry/sdk-metrics');
const { BatchLogRecordProcessor } = require('@opentelemetry/sdk-logs');
const {
  defaultResource,
  resourceFromAttributes,
} = require('@opentelemetry/resources');
const { HttpInstrumentation } = require('@opentelemetry/instrumentation-http');

const endpoint = process.env.OTEL_EXPORTER_OTLP_ENDPOINT || 'http://otel-collector:4317';
const serviceName = process.env.OTEL_SERVICE_NAME || 'example-node';
const environment = process.env.DEPLOYMENT_ENVIRONMENT || 'dev';
const serviceVersion = process.env.SERVICE_VERSION || '0.1.0';

const resource = defaultResource().merge(
  resourceFromAttributes({
    'service.name': serviceName,
    'service.version': serviceVersion,
    'deployment.environment.name': environment,
  }),
);

const sdk = new NodeSDK({
  resource,
  traceExporter: new OTLPTraceExporter({ url: endpoint }),
  metricReaders: [
    new PeriodicExportingMetricReader({
      exporter: new OTLPMetricExporter({ url: endpoint }),
      exportIntervalMillis: 10000,
    }),
  ],
  logRecordProcessors: [
    new BatchLogRecordProcessor({
      exporter: new OTLPLogExporter({ url: endpoint }),
    }),
  ],
  instrumentations: [
    new HttpInstrumentation({
      ignoreIncomingRequestHook: (request) => request.url === '/healthz',
    }),
  ],
});

sdk.start();

async function shutdown() {
  await sdk.shutdown();
}

module.exports = { shutdown };

