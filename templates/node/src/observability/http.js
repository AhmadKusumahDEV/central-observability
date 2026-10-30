'use strict';

const {
  context,
  propagation,
  SpanKind,
  SpanStatusCode,
  trace,
} = require('@opentelemetry/api');
const { getRuntime } = require('./runtime');
const { emitLog } = require('./log');

function safeRoute(value) {
  if (Array.isArray(value)) value = value[0];
  if (typeof value !== 'string' || value.trim() === '') return 'unmatched';
  if (value.includes('?')) return 'unmatched';
  return value;
}

function beginHttpObservation(req, res, options = {}) {
  const runtime = options.runtime || getRuntime();
  const method = req.method || 'GET';
  const resolveRoute = typeof options.route === 'function'
    ? options.route
    : () => 'unmatched';
  const initialRoute = safeRoute(resolveRoute(req));
  const parent = propagation.extract(context.active(), req.headers || {});
  const span = runtime.tracer.startSpan(
    `${method} ${initialRoute}`,
    {
      kind: SpanKind.SERVER,
      attributes: {
        'http.request.method': method,
        'http.route': initialRoute,
      },
    },
    parent,
  );
  const requestContext = trace.setSpan(parent, span);
  const started = process.hrtime.bigint();
  let finished = false;

  function finish(statusCode, error) {
    if (finished) return;
    finished = true;
    const route = safeRoute(resolveRoute(req));
    const status = Number(statusCode) || 500;
    const elapsed = Number(process.hrtime.bigint() - started) / 1e9;
    const attributes = {
      'http.request.method': method,
      'http.route': route,
      'http.response.status_code': status,
    };

    span.updateName(`${method} ${route}`);
    span.setAttributes(attributes);
    if (status >= 500 || error) {
      span.setStatus({ code: SpanStatusCode.ERROR, message: error?.message });
    }
    if (error) span.recordException(error);

    runtime.duration.record(elapsed, attributes, requestContext);
    emitLog(
      runtime,
      status >= 500 ? 'error' : status >= 400 ? 'warn' : 'info',
      'http request',
      attributes,
      requestContext,
    );
    span.end();
  }

  res.once('finish', () => finish(res.statusCode));
  res.once('close', () => {
    if (!res.writableFinished) finish(499);
  });

  return {
    context: requestContext,
    finish,
    run(fn) {
      return context.with(requestContext, fn);
    },
  };
}

module.exports = { beginHttpObservation, safeRoute };
