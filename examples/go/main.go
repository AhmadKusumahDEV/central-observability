package main

import (
	"context"
	"database/sql"
	"errors"
	"fmt"
	"io"
	"net/http"
	"os"
	"os/signal"
	"strconv"
	"syscall"
	"time"

	"github.com/XSAM/otelsql"
	"go.opentelemetry.io/contrib/instrumentation/net/http/otelhttp"
	"go.opentelemetry.io/otel"
	"go.opentelemetry.io/otel/attribute"
	"go.opentelemetry.io/otel/codes"
	"go.opentelemetry.io/otel/exporters/otlp/otlplog/otlploggrpc"
	"go.opentelemetry.io/otel/exporters/otlp/otlpmetric/otlpmetricgrpc"
	"go.opentelemetry.io/otel/exporters/otlp/otlptrace/otlptracegrpc"
	otellog "go.opentelemetry.io/otel/log"
	"go.opentelemetry.io/otel/metric"
	"go.opentelemetry.io/otel/propagation"
	sdklog "go.opentelemetry.io/otel/sdk/log"
	sdkmetric "go.opentelemetry.io/otel/sdk/metric"
	"go.opentelemetry.io/otel/sdk/resource"
	sdktrace "go.opentelemetry.io/otel/sdk/trace"
	"go.opentelemetry.io/otel/trace"
	_ "modernc.org/sqlite"
)

const instrumentationName = "example.com/central-observability-demo"

type application struct {
	tracer        trace.Tracer
	logger        otellog.Logger
	duration      metric.Float64Histogram
	heartbeat     metric.Float64Gauge
	db            *sql.DB
	http          *http.Client
	downstreamURL string
}

func main() {
	ctx, stop := signal.NotifyContext(context.Background(), os.Interrupt, syscall.SIGTERM)
	defer stop()

	app, cleanup, err := setup(ctx)
	if err != nil {
		fmt.Fprintf(os.Stderr, "setup OpenTelemetry: %v\n", err)
		os.Exit(1)
	}
	defer cleanup()

	go app.emitHeartbeat(ctx)

	mux := http.NewServeMux()
	mux.HandleFunc("/healthz", func(w http.ResponseWriter, _ *http.Request) {
		w.Header().Set("Content-Type", "text/plain; charset=utf-8")
		_, _ = w.Write([]byte("ok\n"))
	})
	mux.HandleFunc("/", app.handleWork)

	addr := env("APP_ADDR", ":8080")
	server := &http.Server{
		Addr: addr,
		Handler: otelhttp.NewHandler(
			mux,
			"http.server",
			otelhttp.WithSpanNameFormatter(func(_ string, r *http.Request) string {
				return r.Method + " " + switchRoute(r.URL.Path)
			}),
		),
		ReadHeaderTimeout: 5 * time.Second,
	}

	go func() {
		fmt.Fprintf(os.Stderr, "demo app listening on %s\n", addr)
		if err := server.ListenAndServe(); err != nil && !errors.Is(err, http.ErrServerClosed) {
			fmt.Fprintf(os.Stderr, "http server: %v\n", err)
			os.Exit(1)
		}
	}()

	<-ctx.Done()
	shutdownCtx, cancel := context.WithTimeout(context.Background(), 10*time.Second)
	defer cancel()
	_ = server.Shutdown(shutdownCtx)
}

func setup(ctx context.Context) (*application, func(), error) {
	endpoint := env("OTEL_EXPORTER_OTLP_ENDPOINT", "http://otel-collector:4317")
	serviceName := env("OTEL_SERVICE_NAME", "go-demo")
	serviceVersion := env("SERVICE_VERSION", "0.1.0")
	environment := os.Getenv("DEPLOYMENT_ENVIRONMENT")

	attributes := []attribute.KeyValue{
		attribute.String("service.name", serviceName),
		attribute.String("service.version", serviceVersion),
	}
	if environment != "" {
		attributes = append(attributes, attribute.String("deployment.environment.name", environment))
	}

	res, err := resource.New(ctx,
		resource.WithFromEnv(),
		resource.WithTelemetrySDK(),
		resource.WithAttributes(attributes...),
	)
	if err != nil {
		return nil, nil, fmt.Errorf("build resource: %w", err)
	}

	traceExporter, err := otlptracegrpc.New(ctx, otlptracegrpc.WithEndpointURL(endpoint))
	if err != nil {
		return nil, nil, fmt.Errorf("create trace exporter: %w", err)
	}
	tracerProvider := sdktrace.NewTracerProvider(
		sdktrace.WithBatcher(traceExporter),
		sdktrace.WithResource(res),
		sdktrace.WithSampler(sdktrace.ParentBased(sdktrace.TraceIDRatioBased(1))),
	)

	metricExporter, err := otlpmetricgrpc.New(ctx, otlpmetricgrpc.WithEndpointURL(endpoint))
	if err != nil {
		return nil, nil, fmt.Errorf("create metric exporter: %w", err)
	}
	meterProvider := sdkmetric.NewMeterProvider(
		sdkmetric.WithReader(sdkmetric.NewPeriodicReader(metricExporter, sdkmetric.WithInterval(10*time.Second))),
		sdkmetric.WithResource(res),
	)

	logExporter, err := otlploggrpc.New(ctx, otlploggrpc.WithEndpointURL(endpoint))
	if err != nil {
		return nil, nil, fmt.Errorf("create log exporter: %w", err)
	}
	loggerProvider := sdklog.NewLoggerProvider(
		sdklog.WithProcessor(sdklog.NewBatchProcessor(logExporter)),
		sdklog.WithResource(res),
	)

	otel.SetTracerProvider(tracerProvider)
	otel.SetMeterProvider(meterProvider)
	otel.SetLoggerProvider(loggerProvider)
	otel.SetTextMapPropagator(propagation.TraceContext{})

	meter := meterProvider.Meter(instrumentationName)
	duration, err := meter.Float64Histogram(
		"http.server.request.duration",
		metric.WithUnit("s"),
		metric.WithDescription("HTTP server request duration"),
		metric.WithExplicitBucketBoundaries(
			0.005, 0.01, 0.025, 0.05, 0.075, 0.1, 0.25, 0.5, 0.75, 1, 2.5, 5, 10,
		),
	)
	if err != nil {
		return nil, nil, fmt.Errorf("create duration histogram: %w", err)
	}
	heartbeat, err := meter.Float64Gauge(
		"app.heartbeat.timestamp",
		metric.WithUnit("s"),
		metric.WithDescription("Unix timestamp of the last application heartbeat"),
	)
	if err != nil {
		return nil, nil, fmt.Errorf("create heartbeat gauge: %w", err)
	}

	database, err := otelsql.Open("sqlite", "file:demo?mode=memory&cache=shared")
	if err != nil {
		return nil, nil, fmt.Errorf("open sqlite database: %w", err)
	}
	database.SetMaxOpenConns(1)
	if _, err := database.ExecContext(ctx,
		"CREATE TABLE IF NOT EXISTS work_items ("+
			"id INTEGER PRIMARY KEY AUTOINCREMENT,"+
			"created_at TEXT NOT NULL"+
			")",
	); err != nil {
		_ = database.Close()
		return nil, nil, fmt.Errorf("create demo table: %w", err)
	}

	httpClient := &http.Client{
		Timeout:   3 * time.Second,
		Transport: otelhttp.NewTransport(http.DefaultTransport),
	}

	app := &application{
		tracer:        tracerProvider.Tracer(instrumentationName),
		logger:        loggerProvider.Logger(instrumentationName),
		duration:      duration,
		heartbeat:     heartbeat,
		db:            database,
		http:          httpClient,
		downstreamURL: "http://127.0.0.1:8080/downstream",
	}

	cleanup := func() {
		shutdownCtx, cancel := context.WithTimeout(context.Background(), 10*time.Second)
		defer cancel()
		_ = database.Close()
		_ = loggerProvider.Shutdown(shutdownCtx)
		_ = meterProvider.Shutdown(shutdownCtx)
		_ = tracerProvider.Shutdown(shutdownCtx)
	}
	return app, cleanup, nil
}

func (a *application) handleWork(w http.ResponseWriter, r *http.Request) {
	route := switchRoute(r.URL.Path)
	started := time.Now()
	ctx, span := a.tracer.Start(
		r.Context(),
		"process "+route,
		trace.WithSpanKind(trace.SpanKindInternal),
		trace.WithAttributes(
			attribute.String("http.request.method", r.Method),
			attribute.String("http.route", route),
			attribute.String("url.path", r.URL.Path),
		),
	)
	defer span.End()

	statusCode := http.StatusOK
	var requestErr error
	switch r.URL.Path {
	case "/error":
		statusCode = http.StatusInternalServerError
		requestErr = errors.New("demo dependency failure")
		span.RecordError(requestErr)
		span.SetStatus(codes.Error, requestErr.Error())
	case "/slow":
		delay := 900 * time.Millisecond
		if rawDelay := r.URL.Query().Get("delay_ms"); rawDelay != "" {
			if ms, err := strconv.Atoi(rawDelay); err == nil && ms > 0 && ms <= 5000 {
				delay = time.Duration(ms) * time.Millisecond
			}
		}
		time.Sleep(delay)
	case "/work":
		if err := a.callDownstream(ctx); err != nil {
			statusCode = http.StatusInternalServerError
			requestErr = fmt.Errorf("call downstream: %w", err)
			span.RecordError(requestErr)
			span.SetStatus(codes.Error, requestErr.Error())
			break
		}
		if err := a.recordWork(ctx); err != nil {
			statusCode = http.StatusInternalServerError
			requestErr = fmt.Errorf("write database: %w", err)
			span.RecordError(requestErr)
			span.SetStatus(codes.Error, requestErr.Error())
			break
		}
		time.Sleep(20 * time.Millisecond)
	default:
		time.Sleep(5 * time.Millisecond)
	}

	duration := time.Since(started)
	span.SetAttributes(attribute.Int("http.response.status_code", statusCode))
	a.duration.Record(
		ctx,
		duration.Seconds(),
		metric.WithAttributes(
			attribute.String("http.request.method", r.Method),
			attribute.String("http.route", route),
			attribute.Int("http.response.status_code", statusCode),
		),
	)

	if requestErr != nil {
		serverSpan := trace.SpanFromContext(r.Context())
		serverSpan.RecordError(requestErr)
		serverSpan.SetStatus(codes.Error, requestErr.Error())
		a.emitLog(ctx, otellog.SeverityError, "ERROR", requestErr.Error(), route, statusCode)
		w.Header().Set("Content-Type", "application/json")
		w.WriteHeader(statusCode)
		_, _ = fmt.Fprintf(w, "{\"error\":%q,\"trace_id\":%q}\n", requestErr.Error(), span.SpanContext().TraceID().String())
		return
	}

	a.emitLog(ctx, otellog.SeverityInfo, "INFO", "request completed", route, statusCode)
	w.Header().Set("Content-Type", "application/json")
	_, _ = fmt.Fprintf(w, "{\"status\":\"ok\",\"duration_ms\":%d,\"trace_id\":%q}\n", duration.Milliseconds(), span.SpanContext().TraceID().String())
}

func (a *application) callDownstream(ctx context.Context) error {
	request, err := http.NewRequestWithContext(ctx, http.MethodGet, a.downstreamURL, nil)
	if err != nil {
		return err
	}
	response, err := a.http.Do(request)
	if err != nil {
		return err
	}
	defer response.Body.Close()
	_, _ = io.Copy(io.Discard, io.LimitReader(response.Body, 1024))
	if response.StatusCode >= http.StatusBadRequest {
		return fmt.Errorf("downstream returned %d", response.StatusCode)
	}
	return nil
}

func (a *application) recordWork(ctx context.Context) error {
	_, err := a.db.ExecContext(
		ctx,
		"INSERT INTO work_items (created_at) VALUES (?)",
		time.Now().UTC().Format(time.RFC3339Nano),
	)
	return err
}

func (a *application) emitLog(ctx context.Context, severity otellog.Severity, severityText, message, route string, statusCode int) {
	record := otellog.Record{}
	record.SetTimestamp(time.Now())
	record.SetObservedTimestamp(time.Now())
	record.SetSeverity(severity)
	record.SetSeverityText(severityText)
	record.SetBody(attribute.StringValue(message))
	record.AddAttributes(
		attribute.String("http.route", route),
		attribute.Int("http.response.status_code", statusCode),
	)

	spanContext := trace.SpanContextFromContext(ctx)
	if spanContext.IsValid() {
		record.AddAttributes(
			attribute.String("trace_id", spanContext.TraceID().String()),
			attribute.String("span_id", spanContext.SpanID().String()),
		)
	}
	a.logger.Emit(ctx, record)
}

func (a *application) emitHeartbeat(ctx context.Context) {
	recordHeartbeat := func() {
		a.heartbeat.Record(ctx, float64(time.Now().Unix()))
	}
	recordHeartbeat()
	ticker := time.NewTicker(10 * time.Second)
	defer ticker.Stop()
	for {
		select {
		case <-ctx.Done():
			return
		case <-ticker.C:
			recordHeartbeat()
		}
	}
}

func switchRoute(path string) string {
	switch path {
	case "/error", "/slow", "/work", "/downstream":
		return path
	default:
		return "/other"
	}
}

func env(key, fallback string) string {
	if value := os.Getenv(key); value != "" {
		return value
	}
	return fallback
}
