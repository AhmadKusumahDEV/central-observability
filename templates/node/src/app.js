'use strict';

const http = require('node:http');
const { context, trace } = require('@opentelemetry/api');
const observability = require('./observability');

const routes = new Map([
  ['/healthz', '/healthz'],
  ['/work', '/work'],
  ['/error', '/error'],
  ['/slow', '/slow'],
]);

function routeOf(req) {
  try {
    return routes.get(new URL(req.url, 'http://localhost').pathname) || 'unmatched';
  } catch {
    return 'unmatched';
  }
}

function traceID() {
  return trace.getSpan(context.active())?.spanContext().traceId || '';
}

function json(res, status, body) {
  res.writeHead(status, { 'content-type': 'application/json' });
  res.end(`${JSON.stringify(body)}\n`);
}

async function handle(req, res) {
  const url = new URL(req.url, 'http://localhost');
  if (url.pathname === '/healthz') {
    res.writeHead(200, { 'content-type': 'text/plain; charset=utf-8' });
    res.end('ok\n');
    return;
  }

  if (url.pathname === '/error') {
    const error = new Error('contoh kegagalan untuk verifikasi observability');
    trace.getSpan(context.active())?.recordException(error);
    observability.log('error', error.message, {
      'http.route': '/error',
    });
    json(res, 500, { error: error.message, trace_id: traceID() });
    return;
  }

  if (url.pathname === '/slow') {
    const delay = Number(url.searchParams.get('delay_ms') || '900');
    await new Promise((resolve) => setTimeout(resolve, Math.min(Math.max(delay, 1), 5000)));
    json(res, 200, { status: 'ok', duration_ms: delay, trace_id: traceID() });
    return;
  }

  if (url.pathname === '/work') {
    observability.log('info', 'work completed', {
      work_item: 'example',
    });
    json(res, 200, { status: 'ok', trace_id: traceID() });
    return;
  }

  json(res, 404, { error: 'not found', trace_id: traceID() });
}

const server = http.createServer((req, res) => {
  const observation = observability.beginHttpObservation(req, res, { route: routeOf });
  observation.run(() => {
    handle(req, res).catch((error) => {
      trace.getSpan(context.active())?.recordException(error);
      if (!res.headersSent) json(res, 500, { error: 'internal error', trace_id: traceID() });
      else res.end();
    });
  });
});

const port = Number(process.env.PORT || 8080);
const host = process.env.HOST || '0.0.0.0';
server.listen(port, host, () => {
  observability.log('info', 'http server listening', {
    'server.address': host,
    'server.port': port,
  });
});

let stopping = false;
async function stop(signal) {
  if (stopping) return;
  stopping = true;
  observability.log('info', 'shutdown requested', { signal });
  server.closeIdleConnections?.();
  await new Promise((resolve) => {
    const timer = setTimeout(() => {
      server.closeAllConnections?.();
      resolve();
    }, 10000);
    timer.unref?.();
    server.close(() => {
      clearTimeout(timer);
      resolve();
    });
  });
  await observability.shutdown();
  process.exit(0);
}

process.once('SIGTERM', () => stop('SIGTERM'));
process.once('SIGINT', () => stop('SIGINT'));
