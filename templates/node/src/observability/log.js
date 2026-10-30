'use strict';

const { context, isSpanContextValid, trace } = require('@opentelemetry/api');

const SEVERITY = {
  debug: 5,
  info: 9,
  warn: 13,
  error: 17,
};

function emitLog(runtime, level, message, attributes = {}, ctx = context.active()) {
  const spanContext = trace.getSpan(ctx)?.spanContext();
  const ids = spanContext && isSpanContextValid(spanContext)
    ? { trace_id: spanContext.traceId, span_id: spanContext.spanId }
    : {};

  try {
    runtime.logger.emit({
      severityNumber: SEVERITY[level] || SEVERITY.info,
      severityText: String(level).toUpperCase(),
      body: message,
      attributes: { ...attributes, ...ids },
      context: ctx,
    });
  } catch {
    // Telemetry tidak boleh menggagalkan request aplikasi.
  }
}

module.exports = { emitLog };
