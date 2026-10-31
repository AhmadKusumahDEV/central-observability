'use strict';

const { context, propagation } = require('@opentelemetry/api');
const { start, shutdown, isStarted } = require('./start');
const { getRuntime } = require('./runtime');
const { beginHttpObservation } = require('./http');
const { emitLog } = require('./log');

function log(level, message, attributes = {}, ctx = context.active()) {
  emitLog(getRuntime(), level, message, attributes, ctx);
}

function injectTraceHeaders(carrier = {}, ctx = context.active()) {
  propagation.inject(ctx, carrier);
  return carrier;
}

module.exports = {
  start,
  shutdown,
  isStarted,
  beginHttpObservation,
  emitLog,
  log,
  injectTraceHeaders,
  getTracer: () => getRuntime().tracer,
  getMeter: () => getRuntime().meter,
  getLogger: () => getRuntime().logger,
};
