import os
import sqlite3
import threading
import time
from typing import Any

from telemetry import app_logger, tracer

import requests
from flask import Flask, g, jsonify, request
from opentelemetry import metrics, trace
from opentelemetry.trace import Status, StatusCode


app = Flask(__name__)
meter = metrics.get_meter("example-python", "0.1.0")

request_duration = meter.create_histogram(
    "http.server.request.duration",
    unit="s",
    description="HTTP server request duration",
    explicit_bucket_boundaries_advisory=[
        0.005,
        0.01,
        0.025,
        0.05,
        0.075,
        0.1,
        0.25,
        0.5,
        0.75,
        1,
        2.5,
        5,
        10,
    ],
)


def _heartbeat(options):
    return [metrics.Observation(float(time.time()))]


meter.create_observable_gauge(
    "app.heartbeat.timestamp",
    callbacks=[_heartbeat],
    unit="s",
    description="Unix timestamp of the last application heartbeat",
)

database = sqlite3.connect(":memory:", check_same_thread=False)
database.execute(
    "CREATE TABLE IF NOT EXISTS work_items ("
    "id INTEGER PRIMARY KEY AUTOINCREMENT,"
    "created_at TEXT NOT NULL"
    ")"
)
database.commit()
database_lock = threading.Lock()


def route_for(path: str) -> str:
    if path in {"/work", "/error", "/slow", "/downstream"}:
        return path
    return "/other"


def trace_id() -> str:
    span_context = trace.get_current_span().get_span_context()
    if span_context.is_valid:
        return format(span_context.trace_id, "032x")
    return ""


def log_request(message: str, level: int, route: str, status: int) -> None:
    app_logger.log(
        level,
        message,
        extra={
            "http.route": route,
            "http.response.status_code": status,
            "trace_id": trace_id(),
        },
    )


def write_work() -> None:
    with tracer.start_as_current_span(
        "db.sqlite.insert",
        attributes={
            "db.system.name": "sqlite",
            "db.operation.name": "INSERT",
            "db.collection.name": "work_items",
            "db.namespace": "demo",
        },
    ):
        with database_lock:
            database.execute(
                "INSERT INTO work_items (created_at) VALUES (?)",
                (time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime()),),
            )
            database.commit()


@app.before_request
def before_request() -> None:
    if request.path == "/healthz":
        return
    g.started = time.perf_counter()
    g.route = route_for(request.path)


@app.after_request
def after_request(response):
    if request.path == "/healthz":
        return response

    elapsed = time.perf_counter() - getattr(g, "started", time.perf_counter())
    route = getattr(g, "route", route_for(request.path))
    attributes = {
        "http.request.method": request.method,
        "http.route": route,
        "http.response.status_code": response.status_code,
    }
    request_duration.record(elapsed, attributes)

    span = trace.get_current_span()
    span.set_attribute("http.route", route)
    span.set_attribute("http.response.status_code", response.status_code)

    if response.status_code < 500:
        log_request("request completed", 20, route, response.status_code)
    return response


@app.errorhandler(Exception)
def handle_error(error: Exception):
    span = trace.get_current_span()
    span.record_exception(error)
    span.set_status(Status(StatusCode.ERROR, str(error)))
    route = route_for(request.path)
    log_request(str(error), 40, route, 500)
    return (
        jsonify({"error": str(error), "trace_id": trace_id()}),
        500,
    )


def _sleep_from_query() -> None:
    delay_ms = request.args.get("delay_ms", default="900", type=str)
    try:
        delay = int(delay_ms)
    except ValueError:
        delay = 900
    time.sleep(min(max(delay, 1), 5000) / 1000.0)


def _ok(payload: dict[str, Any]):
    payload = {**payload, "trace_id": trace_id()}
    return jsonify(payload)


@app.get("/healthz")
def health():
    return "ok\n", 200, {"Content-Type": "text/plain; charset=utf-8"}


@app.get("/work")
def work():
    response = requests.get(
        "http://127.0.0.1:8080/downstream",
        timeout=3,
    )
    response.raise_for_status()
    write_work()
    time.sleep(0.02)
    return _ok({"status": "ok"})


@app.get("/downstream")
def downstream():
    return _ok({"status": "ok"})


@app.get("/error")
def error():
    raise RuntimeError("demo dependency failure")


@app.get("/slow")
def slow():
    _sleep_from_query()
    return _ok({"status": "ok"})


if __name__ == "__main__":
    app.run(host="0.0.0.0", port=int(os.getenv("PORT", "8080")))

