'use strict';

const { logs, metrics, trace } = require('@opentelemetry/api');

let runtime;

function createRuntime(config) {
  const tracer = trace.getTracer('central-observability/node');
  const meter = metrics.getMeter('central-observability/node');
  const logger = logs.getLogger('central-observability/node');

  const duration = meter.createHistogram('http.server.request.duration', {
    unit: 's',
    description: 'Durasi request HTTP server',
    advice: {
      explicitBucketBoundaries: [
        0.005, 0.01, 0.025, 0.05, 0.075, 0.1,
        0.25, 0.5, 0.75, 1, 2.5, 5, 7.5, 10,
      ],
    },
  });

  const heartbeat = meter.createObservableGauge('app.heartbeat.timestamp', {
    unit: 's',
    description: 'Unix timestamp heartbeat terakhir aplikasi',
  });
  heartbeat.addCallback((result) => {
    result.observe(Date.now() / 1000);
  });

  return {
    config,
    tracer,
    meter,
    logger,
    duration,
    heartbeat,
  };
}

function setRuntime(value) {
  runtime = value;
}

function getRuntime() {
  if (!runtime) {
    throw new Error('observability belum diinisialisasi; muat instrumentation.js sebelum aplikasi');
  }
  return runtime;
}

function clearRuntime() {
  runtime = undefined;
}

module.exports = { createRuntime, setRuntime, getRuntime, clearRuntime };
