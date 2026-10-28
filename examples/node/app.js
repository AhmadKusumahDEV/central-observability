'use strict';

const http = require('node:http');
const { DatabaseSync } = require('node:sqlite');
const {
  context,
  logs,
  SpanKind,
  SpanStatusCode,
  SeverityNumber,
  metrics,
  trace,
} = require('@opentelemetry/api');
const { shutdown } = require('./instrumentation');

const tracer = trace.getTracer('example-node', '0.1.0');
const meter = metrics.getMeter('example-node', '0.1.0');
const logger = logs.getLogger('example-node', '0.1.0');

const requestDuration = meter.createHistogram('http.server.request.duration', {
  unit: 's',
  description: 'HTTP server request duration',
  advice: {
    explicitBucketBoundaries: [
      0.005, 0.01, 0.025, 0.05, 0.075, 0.1,
      0.25, 0.5, 0.75, 1, 2.5, 5, 10,
    ],
  },
});

const heartbeat = meter.createObservableGauge('app.heartbeat.timestamp', {
  unit: 's',
  description: 'Unix timestamp of the last application heartbeat',
});
heartbeat.addCallback((result) => {
  result.observe(Date.now() / 1000);
});

const database = new DatabaseSync(':memory:');
database.exec(
  'CREATE TABLE IF NOT EXISTS work_items (' +
    'id INTEGER PRIMARY KEY AUTOINCREMENT,' +
    'created_at TEXT NOT NULL' +
  ')',
);

function routeFor(pathname) {
  switch (pathname) {
    case '/work':
    case '/error':
    case '/slow':
    case '/downstream':
      return pathname;
    default:
      return '/other';
  }
}

function queueWork(activeContext) {
  return tracer.startActiveSpan(
    'db.sqlite.insert',
    {
      kind: SpanKind.CLIENT,
      attributes: {
        'db.system.name': 'sqlite',
        'db.operation.name': 'INSERT',
        'db.collection.name': 'work_items',
        'db.namespace': 'demo',
      },
    },
    activeContext,
    (span) => {
      try {
        database
          .prepare('INSERT INTO work_items (created_at) VALUES (?)')
          .run(new Date().toISOString());
      } catch (error) {
        span.recordException(error);
        span.setStatus({ code: SpanStatusCode.ERROR, message: error.message });
        throw error;
      } finally {
        span.end();
      }
    },
  );
}

function callDownstream() {
  return new Promise((resolve, reject) => {
    const request = http.get('http://127.0.0.1:8080/downstream', (response) => {
      response.resume();
      response.on('end', () => {
        if (response.statusCode >= 400) {
          reject(new Error('downstream returned ' + response.statusCode));
          return;
        }
        resolve();
      });
    });
    request.setTimeout(3000, () => {
      request.destroy(new Error('downstream timeout'));
    });
    request.on('error', reject);
  });
}

function sleep(milliseconds) {
  return new Promise((resolve) => setTimeout(resolve, milliseconds));
}

function emitLog(severityNumber, severityText, message, route, statusCode) {
  const active = context.active();
  const span = trace.getSpan(active);
  const spanContext = span ? span.spanContext() : undefined;
  const attributes = {
    'http.route': route,
    'http.response.status_code': statusCode,
  };
  if (spanContext) {
    attributes.trace_id = spanContext.traceId;
    attributes.span_id = spanContext.spanId;
  }
  logger.emit(
    {
      severityNumber,
      severityText,
      body: message,
      attributes,
      context: active,
    },
  );
}

async function handleRequest(request, response) {
  const url = new URL(request.url, 'http://127.0.0.1');
  if (url.pathname === '/healthz') {
    response.writeHead(200, { 'content-type': 'text/plain; charset=utf-8' });
    response.end('ok\n');
    return;
  }

  const route = routeFor(url.pathname);
  const started = process.hrtime.bigint();

  await tracer.startActiveSpan(
    'process ' + route,
    {
      kind: SpanKind.INTERNAL,
      attributes: {
        'http.request.method': request.method,
        'http.route': route,
        'url.path': url.pathname,
      },
    },
    async (span) => {
      let statusCode = 200;
      let failure;
      try {
        if (url.pathname === '/error') {
          throw new Error('demo dependency failure');
        }
        if (url.pathname === '/slow') {
          const delay = Number(url.searchParams.get('delay_ms') || '900');
          await sleep(Math.min(Math.max(delay, 1), 5000));
        }
        if (url.pathname === '/work') {
          await callDownstream();
          queueWork(context.active());
          await sleep(20);
        }
      } catch (error) {
        failure = error;
        statusCode = 500;
        span.recordException(error);
        span.setStatus({ code: SpanStatusCode.ERROR, message: error.message });
      } finally {
        const elapsed = Number(process.hrtime.bigint() - started) / 1e9;
        const attributes = {
          'http.request.method': request.method,
          'http.route': route,
          'http.response.status_code': statusCode,
        };
        span.setAttributes(attributes);
        requestDuration.record(elapsed, attributes, context.active());

        if (failure) {
          emitLog(
            SeverityNumber.ERROR,
            'ERROR',
            failure.message,
            route,
            statusCode,
          );
          response.writeHead(statusCode, { 'content-type': 'application/json' });
          response.end(JSON.stringify({
            error: failure.message,
            trace_id: span.spanContext().traceId,
          }) + '\n');
        } else {
          emitLog(
            SeverityNumber.INFO,
            'INFO',
            'request completed',
            route,
            statusCode,
          );
          response.writeHead(statusCode, { 'content-type': 'application/json' });
          response.end(JSON.stringify({
            status: 'ok',
            duration_ms: Math.round(elapsed * 1000),
            trace_id: span.spanContext().traceId,
          }) + '\n');
        }
        span.end();
      }
    },
  );
}

const server = http.createServer((request, response) => {
  handleRequest(request, response).catch((error) => {
    console.error(error);
    if (!response.headersSent) {
      response.writeHead(500, { 'content-type': 'text/plain; charset=utf-8' });
    }
    response.end('internal error\n');
  });
});

const port = Number(process.env.PORT || 8080);
server.listen(port, '0.0.0.0', () => {
  console.error('demo app listening on ' + port);
});

async function stop(signal) {
  console.error('received ' + signal + ', shutting down');
  server.close(async () => {
    try {
      await shutdown();
      process.exit(0);
    } catch (error) {
      console.error(error);
      process.exit(1);
    }
  });
}

process.on('SIGTERM', () => stop('SIGTERM'));
process.on('SIGINT', () => stop('SIGINT'));

