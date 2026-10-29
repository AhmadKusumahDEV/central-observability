// Package observability mengirim traces, metrics, dan logs ke satu OTLP
// Collector. Semua sinyal memakai resource attribute yang sama sehingga
// Grafana dapat memfilter data dengan service.name dan
// deployment.environment.name.
//
// Package ini sengaja berupa satu folder tanpa subpackage. Salin folder ini ke
// pkg/observability di project lain, lalu import dengan path module project
// tersebut. Tidak ada import internal yang perlu diubah.
package observability

import (
	"bufio"
	"context"
	"errors"
	"fmt"
	"log/slog"
	"net"
	"net/http"
	"net/url"
	"os"
	"strconv"
	"strings"
	"time"

	"go.opentelemetry.io/contrib/instrumentation/net/http/otelhttp"
	"go.opentelemetry.io/otel"
	"go.opentelemetry.io/otel/attribute"
	"go.opentelemetry.io/otel/codes"
	"go.opentelemetry.io/otel/exporters/otlp/otlplog/otlploggrpc"
	"go.opentelemetry.io/otel/exporters/otlp/otlpmetric/otlpmetricgrpc"
	"go.opentelemetry.io/otel/exporters/otlp/otlptrace/otlptracegrpc"
	otellog "go.opentelemetry.io/otel/log"
	"go.opentelemetry.io/otel/metric"
	metricnoop "go.opentelemetry.io/otel/metric/noop"
	"go.opentelemetry.io/otel/propagation"
	sdklog "go.opentelemetry.io/otel/sdk/log"
	sdkmetric "go.opentelemetry.io/otel/sdk/metric"
	"go.opentelemetry.io/otel/sdk/resource"
	sdktrace "go.opentelemetry.io/otel/sdk/trace"
	"go.opentelemetry.io/otel/trace"
	"go.opentelemetry.io/otel/trace/noop"
)

const (
	instrumentationName = "central-observability/go"
	defaultEndpoint     = "http://otel-collector:4317"
	defaultMetricEvery  = 10 * time.Second
	defaultHeartbeat    = 15 * time.Second
)

// Config mengatur resource dan tiga exporter. Nilai yang kosong diisi dari
// environment variable oleh ConfigFromEnv; Config yang dibuat langsung tetap
// memakai nilai aman di Setup.
type Config struct {
	ServiceName    string
	ServiceVersion string
	Environment    string

	// Endpoint adalah alamat OTLP gRPC, misalnya
	// http://otel-collector:4317 atau https://otel.example.com:443.
	Endpoint string
	// Headers dipakai untuk auth remote, misalnya Authorization dari Caddy.
	Headers map[string]string
	// Insecure mematikan TLS. Biasanya tidak perlu karena skema http:// pada
	// Endpoint sudah memberi tahu exporter untuk memakai koneksi tanpa TLS.
	Insecure bool
	// Compression berisi "gzip" untuk menghemat bandwidth. String kosong
	// berarti tanpa kompresi.
	Compression string

	// SampleRatio 1 menyimpan semua trace; 0.1 menyimpan sekitar 10 persen.
	SampleRatio float64

	LogLevel string

	DisableTraces  bool
	DisableMetrics bool
	DisableLogs    bool

	MetricInterval    time.Duration
	HeartbeatInterval time.Duration
}

// Stack adalah hasil Setup. Logger sudah menjadi slog default. Tracer dan Meter
// dapat dipakai untuk span dan metric buatan aplikasi.
type Stack struct {
	Logger *slog.Logger
	Tracer trace.Tracer
	Meter  metric.Meter

	// Shutdown harus dipanggil setelah HTTP server berhenti. Urutannya: server,
	// lalu Shutdown, agar log terakhir dan span yang masih aktif sempat dikirim.
	Shutdown func(context.Context) error

	duration metric.Float64Histogram
}

// ConfigFromEnv membaca variabel yang lazim dipakai OpenTelemetry. Aplikasi
// hanya perlu mengisi OTEL_SERVICE_NAME,
// OTEL_RESOURCE_ATTRIBUTES, dan OTEL_EXPORTER_OTLP_ENDPOINT.
func ConfigFromEnv() Config {
	cfg := Config{
		ServiceName:       firstNonEmpty(os.Getenv("OTEL_SERVICE_NAME"), resourceAttribute("service.name"), "unknown-service"),
		ServiceVersion:    firstNonEmpty(os.Getenv("OTEL_SERVICE_VERSION"), os.Getenv("SERVICE_VERSION"), resourceAttribute("service.version")),
		Environment:       firstNonEmpty(os.Getenv("OTEL_DEPLOYMENT_ENVIRONMENT"), os.Getenv("DEPLOYMENT_ENVIRONMENT"), resourceAttribute("deployment.environment.name")),
		Endpoint:          firstNonEmpty(os.Getenv("OTEL_EXPORTER_OTLP_ENDPOINT"), defaultEndpoint),
		Headers:           parseKVList(os.Getenv("OTEL_EXPORTER_OTLP_HEADERS")),
		Compression:       strings.TrimSpace(os.Getenv("OTEL_EXPORTER_OTLP_COMPRESSION")),
		SampleRatio:       1,
		LogLevel:          firstNonEmpty(os.Getenv("OBSERVABILITY_LOG_LEVEL"), os.Getenv("LOG_LEVEL"), "info"),
		MetricInterval:    defaultMetricEvery,
		HeartbeatInterval: defaultHeartbeat,
	}

	if value := strings.TrimSpace(os.Getenv("OTEL_EXPORTER_OTLP_INSECURE")); value != "" {
		cfg.Insecure = parseBool(value)
	}
	if value := strings.TrimSpace(os.Getenv("OTEL_TRACES_SAMPLER_ARG")); value != "" {
		if ratio, err := strconv.ParseFloat(value, 64); err == nil {
			cfg.SampleRatio = ratio
		}
	}
	if value := strings.TrimSpace(os.Getenv("OTEL_METRIC_EXPORT_INTERVAL")); value != "" {
		if milliseconds, err := strconv.Atoi(value); err == nil && milliseconds > 0 {
			cfg.MetricInterval = time.Duration(milliseconds) * time.Millisecond
		}
	}
	if value := strings.TrimSpace(os.Getenv("OBSERVABILITY_HEARTBEAT_INTERVAL")); value != "" {
		if seconds, err := strconv.Atoi(value); err == nil && seconds > 0 {
			cfg.HeartbeatInterval = time.Duration(seconds) * time.Second
		}
	}
	if value := strings.TrimSpace(os.Getenv("OBSERVABILITY_ENABLED")); value != "" && !parseBool(value) {
		cfg.DisableTraces = true
		cfg.DisableMetrics = true
		cfg.DisableLogs = true
	}

	return cfg
}

// Setup membuat logger, tracer provider, meter provider, dan logger provider.
// Exporter OTLP membuat koneksi secara lazy, jadi Collector yang sedang mati
// tidak membuat aplikasi gagal start; data akan dikirim saat Collector hidup.
func Setup(ctx context.Context, cfg Config) (*Stack, error) {
	if strings.TrimSpace(cfg.ServiceName) == "" {
		cfg.ServiceName = "unknown-service"
	}
	if strings.TrimSpace(cfg.ServiceVersion) == "" {
		cfg.ServiceVersion = "dev"
	}
	if strings.TrimSpace(cfg.Environment) == "" {
		cfg.Environment = "unknown"
	}
	if strings.TrimSpace(cfg.Endpoint) == "" {
		cfg.Endpoint = defaultEndpoint
	}
	if strings.TrimSpace(cfg.LogLevel) == "" {
		cfg.LogLevel = "info"
	}
	if cfg.SampleRatio <= 0 {
		cfg.SampleRatio = 1
	}
	if cfg.MetricInterval <= 0 {
		cfg.MetricInterval = defaultMetricEvery
	}
	if cfg.HeartbeatInterval <= 0 {
		cfg.HeartbeatInterval = defaultHeartbeat
	}

	res, err := resource.New(ctx,
		resource.WithFromEnv(),
		resource.WithTelemetrySDK(),
		resource.WithAttributes(
			attribute.String("service.name", cfg.ServiceName),
			attribute.String("service.version", cfg.ServiceVersion),
			attribute.String("deployment.environment.name", cfg.Environment),
		),
	)
	if err != nil {
		return nil, fmt.Errorf("membuat resource OpenTelemetry: %w", err)
	}

	var (
		traceProvider *sdktrace.TracerProvider
		meterProvider *sdkmetric.MeterProvider
		logProvider   *sdklog.LoggerProvider
		otelLogger    otellog.Logger
	)

	if !cfg.DisableTraces {
		exporter, err := otlptracegrpc.New(ctx, traceOptions(cfg)...)
		if err != nil {
			return nil, fmt.Errorf("membuat trace exporter: %w", err)
		}
		traceProvider = sdktrace.NewTracerProvider(
			sdktrace.WithBatcher(exporter),
			sdktrace.WithResource(res),
			sdktrace.WithSampler(sdktrace.ParentBased(sdktrace.TraceIDRatioBased(cfg.SampleRatio))),
		)
		otel.SetTracerProvider(traceProvider)
	} else {
		otel.SetTracerProvider(noop.NewTracerProvider())
	}

	if !cfg.DisableMetrics {
		exporter, err := otlpmetricgrpc.New(ctx, metricOptions(cfg)...)
		if err != nil {
			shutdownProviders(ctx, nil, nil, traceProvider)
			return nil, fmt.Errorf("membuat metric exporter: %w", err)
		}
		meterProvider = sdkmetric.NewMeterProvider(
			sdkmetric.WithReader(sdkmetric.NewPeriodicReader(exporter, sdkmetric.WithInterval(cfg.MetricInterval))),
			sdkmetric.WithResource(res),
		)
		otel.SetMeterProvider(meterProvider)
	} else {
		otel.SetMeterProvider(metricnoop.NewMeterProvider())
	}

	if !cfg.DisableLogs {
		exporter, err := otlploggrpc.New(ctx, logOptions(cfg)...)
		if err != nil {
			shutdownProviders(ctx, nil, meterProvider, traceProvider)
			return nil, fmt.Errorf("membuat log exporter: %w", err)
		}
		logProvider = sdklog.NewLoggerProvider(
			sdklog.WithProcessor(sdklog.NewBatchProcessor(exporter)),
			sdklog.WithResource(res),
		)
		otel.SetLoggerProvider(logProvider)
		otelLogger = logProvider.Logger(instrumentationName)
	}

	level := new(slog.LevelVar)
	level.Set(parseLevel(cfg.LogLevel))
	stdout := slog.NewJSONHandler(os.Stdout, &slog.HandlerOptions{Level: level})
	logger := slog.New(&otelHandler{next: stdout, logger: otelLogger, level: level})
	slog.SetDefault(logger)

	otel.SetTextMapPropagator(propagation.NewCompositeTextMapPropagator(
		propagation.TraceContext{},
		propagation.Baggage{},
	))

	meter := otel.Meter(instrumentationName)
	duration, err := meter.Float64Histogram(
		"http.server.request.duration",
		metric.WithUnit("s"),
		metric.WithDescription("Durasi request HTTP server"),
		metric.WithExplicitBucketBoundaries(0.005, 0.01, 0.025, 0.05, 0.075, 0.1, 0.25, 0.5, 0.75, 1, 2.5, 5, 10),
	)
	if err != nil {
		shutdownProviders(ctx, logProvider, meterProvider, traceProvider)
		return nil, fmt.Errorf("membuat histogram HTTP: %w", err)
	}

	heartbeat, err := meter.Float64Gauge(
		"app.heartbeat.timestamp",
		metric.WithUnit("s"),
		metric.WithDescription("Unix timestamp heartbeat terakhir aplikasi"),
	)
	if err != nil {
		shutdownProviders(ctx, logProvider, meterProvider, traceProvider)
		return nil, fmt.Errorf("membuat gauge heartbeat: %w", err)
	}

	heartbeatCtx, stopHeartbeat := context.WithCancel(context.Background())
	go recordHeartbeat(heartbeatCtx, heartbeat, cfg.HeartbeatInterval)

	stack := &Stack{
		Logger:   logger,
		Tracer:   otel.Tracer(instrumentationName),
		Meter:    meter,
		duration: duration,
	}
	stack.Shutdown = func(shutdownCtx context.Context) error {
		stopHeartbeat()

		return errors.Join(
			shutdownProviders(shutdownCtx, logProvider, meterProvider, traceProvider),
		)
	}

	return stack, nil
}

func traceOptions(cfg Config) []otlptracegrpc.Option {
	opts := []otlptracegrpc.Option{otlptracegrpc.WithEndpointURL(cfg.Endpoint)}
	if cfg.Insecure {
		opts = append(opts, otlptracegrpc.WithInsecure())
	}
	if len(cfg.Headers) > 0 {
		opts = append(opts, otlptracegrpc.WithHeaders(cfg.Headers))
	}
	if strings.EqualFold(cfg.Compression, "gzip") {
		opts = append(opts, otlptracegrpc.WithCompressor("gzip"))
	}

	return opts
}

func metricOptions(cfg Config) []otlpmetricgrpc.Option {
	opts := []otlpmetricgrpc.Option{otlpmetricgrpc.WithEndpointURL(cfg.Endpoint)}
	if cfg.Insecure {
		opts = append(opts, otlpmetricgrpc.WithInsecure())
	}
	if len(cfg.Headers) > 0 {
		opts = append(opts, otlpmetricgrpc.WithHeaders(cfg.Headers))
	}
	if strings.EqualFold(cfg.Compression, "gzip") {
		opts = append(opts, otlpmetricgrpc.WithCompressor("gzip"))
	}

	return opts
}

func logOptions(cfg Config) []otlploggrpc.Option {
	opts := []otlploggrpc.Option{otlploggrpc.WithEndpointURL(cfg.Endpoint)}
	if cfg.Insecure {
		opts = append(opts, otlploggrpc.WithInsecure())
	}
	if len(cfg.Headers) > 0 {
		opts = append(opts, otlploggrpc.WithHeaders(cfg.Headers))
	}
	if strings.EqualFold(cfg.Compression, "gzip") {
		opts = append(opts, otlploggrpc.WithCompressor("gzip"))
	}

	return opts
}

func shutdownProviders(ctx context.Context, logs *sdklog.LoggerProvider, metrics *sdkmetric.MeterProvider, traces *sdktrace.TracerProvider) error {
	var errs []error
	if logs != nil {
		errs = append(errs, logs.Shutdown(ctx))
	}
	if metrics != nil {
		errs = append(errs, metrics.Shutdown(ctx))
	}
	if traces != nil {
		errs = append(errs, traces.Shutdown(ctx))
	}

	return errors.Join(errs...)
}

func recordHeartbeat(ctx context.Context, gauge metric.Float64Gauge, interval time.Duration) {
	record := func() {
		gauge.Record(context.Background(), float64(time.Now().Unix()))
	}
	record()

	ticker := time.NewTicker(interval)
	defer ticker.Stop()
	for {
		select {
		case <-ctx.Done():
			return
		case <-ticker.C:
			record()
		}
	}
}

// Handler membuat span server, mencatat histogram
// http.server.request.duration, dan menulis satu access log. Route diambil dari
// http.Request.Pattern bila tersedia. Route kosong menjadi "unmatched", bukan
// URL mentah, agar label metrics tidak meledak.
//
// Untuk Gin atau Echo, jangan membungkus seluruh engine dengan Handler ini bila
// route template hanya diketahui framework. Pakai otelgin atau echo-otel untuk
// span dan metric, lalu panggil LogRequest dari middleware aplikasi.
func (s *Stack) Handler(next http.Handler) http.Handler {
	return s.HandlerWithRoute(next, routeFromRequest)
}

// HandlerWithRoute sama seperti Handler, tetapi route ditentukan oleh routeFunc.
// Gunakan ini untuk http.ServeMux: pola baru diketahui setelah mux memilih
// handler, sedangkan wrapper ini berjalan sebelum mux. Kembalikan route template
// seperti "/v1/orders/{id}", bukan URL mentah. Kembalikan string kosong untuk
// request yang tidak dikenal; package akan memakai "unmatched".
func (s *Stack) HandlerWithRoute(next http.Handler, routeFunc func(*http.Request) string) http.Handler {
	if s == nil {
		return next
	}
	if routeFunc == nil {
		routeFunc = routeFromRequest
	}

	return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		route := routeOrUnmatched(routeFunc(r))
		ctx, span := s.Tracer.Start(r.Context(), r.Method+" "+route,
			trace.WithSpanKind(trace.SpanKindServer),
			trace.WithAttributes(
				attribute.String("http.request.method", r.Method),
				attribute.String("http.route", route),
			),
		)
		defer span.End()

		started := time.Now()
		recorder := &statusRecorder{ResponseWriter: w, status: http.StatusOK}
		next.ServeHTTP(recorder, r.WithContext(ctx))
		elapsed := time.Since(started)

		span.SetAttributes(attribute.Int("http.response.status_code", recorder.status))
		if recorder.status >= http.StatusInternalServerError {
			span.SetStatus(codes.Error, http.StatusText(recorder.status))
		}

		s.RecordHTTPDuration(ctx, r.Method, route, recorder.status, elapsed)
		s.LogRequest(ctx, r.Method, route, recorder.status, elapsed)
	})
}

// RecordHTTPDuration mencatat satu observasi histogram. Pakai ini dari adapter
// Gin/Echo hanya jika adapter tersebut belum mencatat
// http.server.request.duration.
func (s *Stack) RecordHTTPDuration(ctx context.Context, method, route string, status int, elapsed time.Duration) {
	if s == nil || s.duration == nil {
		return
	}

	s.duration.Record(ctx, elapsed.Seconds(), metric.WithAttributes(
		attribute.String("http.request.method", method),
		attribute.String("http.route", routeOrUnmatched(route)),
		attribute.Int("http.response.status_code", status),
	))
}

// LogRequest menulis access log dengan trace_id dari context. Adapter Gin/Echo
// dapat memanggil fungsi ini setelah route template diketahui.
func (s *Stack) LogRequest(ctx context.Context, method, route string, status int, elapsed time.Duration) {
	if s == nil || s.Logger == nil {
		return
	}

	attrs := []any{
		"http.method", method,
		"http.route", routeOrUnmatched(route),
		"http.status", status,
		"duration_ms", float64(elapsed.Microseconds()) / 1000,
	}
	switch {
	case status >= http.StatusInternalServerError:
		s.Logger.ErrorContext(ctx, "http request", attrs...)
	case status >= http.StatusBadRequest:
		s.Logger.WarnContext(ctx, "http request", attrs...)
	default:
		s.Logger.InfoContext(ctx, "http request", attrs...)
	}
}

// HTTPClient menambahkan span HTTP keluar dan propagasi traceparent ke client.
// Client asli tidak diubah.
func (s *Stack) HTTPClient(base *http.Client) *http.Client {
	if base == nil {
		base = &http.Client{Timeout: 10 * time.Second}
	}

	clone := *base
	clone.Transport = otelhttp.NewTransport(base.Transport)

	return &clone
}

type statusRecorder struct {
	http.ResponseWriter
	status      int
	wroteHeader bool
}

func (r *statusRecorder) WriteHeader(status int) {
	if r.wroteHeader {
		return
	}
	r.wroteHeader = true
	r.status = status
	r.ResponseWriter.WriteHeader(status)
}

func (r *statusRecorder) Write(data []byte) (int, error) {
	if !r.wroteHeader {
		r.wroteHeader = true
		r.status = http.StatusOK
	}

	return r.ResponseWriter.Write(data)
}

func (r *statusRecorder) Unwrap() http.ResponseWriter {
	return r.ResponseWriter
}

func (r *statusRecorder) Flush() {
	if flusher, ok := r.ResponseWriter.(http.Flusher); ok {
		flusher.Flush()
	}
}

func (r *statusRecorder) Hijack() (net.Conn, *bufio.ReadWriter, error) {
	hijacker, ok := r.ResponseWriter.(http.Hijacker)
	if !ok {
		return nil, nil, errors.New("response writer tidak mendukung hijack")
	}

	return hijacker.Hijack()
}

func routeFromRequest(r *http.Request) string {
	if r.Pattern != "" {
		return r.Pattern
	}

	return "unmatched"
}

func routeOrUnmatched(route string) string {
	if strings.TrimSpace(route) == "" {
		return "unmatched"
	}

	return route
}

type otelHandler struct {
	next   slog.Handler
	logger otellog.Logger
	level  slog.Leveler
	attrs  []slog.Attr
	groups []string
}

func (h *otelHandler) Enabled(ctx context.Context, level slog.Level) bool {
	return h.next.Enabled(ctx, level)
}

func (h *otelHandler) Handle(ctx context.Context, record slog.Record) error {
	spanContext := trace.SpanContextFromContext(ctx)
	if spanContext.IsValid() {
		record.AddAttrs(
			slog.String("trace_id", spanContext.TraceID().String()),
			slog.String("span_id", spanContext.SpanID().String()),
		)
	}

	if err := h.next.Handle(ctx, record); err != nil {
		return err
	}
	if h.logger == nil {
		return nil
	}

	otelRecord := otellog.Record{}
	otelRecord.SetTimestamp(record.Time)
	otelRecord.SetObservedTimestamp(time.Now())
	otelRecord.SetSeverity(severity(record.Level))
	otelRecord.SetSeverityText(strings.ToUpper(record.Level.String()))
	otelRecord.SetBody(attribute.StringValue(record.Message))

	attrs := make([]attribute.KeyValue, 0, len(h.attrs)+record.NumAttrs())
	prefix := strings.Join(h.groups, ".")
	for _, attr := range h.attrs {
		attrs = appendSlogAttr(attrs, prefix, attr)
	}
	record.Attrs(func(attr slog.Attr) bool {
		attrs = appendSlogAttr(attrs, prefix, attr)
		return true
	})
	otelRecord.AddAttributes(attrs...)
	h.logger.Emit(ctx, otelRecord)

	return nil
}

func (h *otelHandler) WithAttrs(attrs []slog.Attr) slog.Handler {
	combined := make([]slog.Attr, 0, len(h.attrs)+len(attrs))
	combined = append(combined, h.attrs...)
	combined = append(combined, attrs...)

	return &otelHandler{
		next:   h.next.WithAttrs(attrs),
		logger: h.logger,
		level:  h.level,
		attrs:  combined,
		groups: h.groups,
	}
}

func (h *otelHandler) WithGroup(name string) slog.Handler {
	if strings.TrimSpace(name) == "" {
		return h
	}
	groups := make([]string, 0, len(h.groups)+1)
	groups = append(groups, h.groups...)
	groups = append(groups, name)

	return &otelHandler{
		next:   h.next.WithGroup(name),
		logger: h.logger,
		level:  h.level,
		attrs:  h.attrs,
		groups: groups,
	}
}

func appendSlogAttr(dst []attribute.KeyValue, prefix string, attr slog.Attr) []attribute.KeyValue {
	attr.Value = attr.Value.Resolve()
	if attr.Equal(slog.Attr{}) {
		return dst
	}

	key := attr.Key
	if prefix != "" {
		key = prefix + "." + key
	}
	if attr.Value.Kind() == slog.KindGroup {
		for _, nested := range attr.Value.Group() {
			dst = appendSlogAttr(dst, key, nested)
		}
		return dst
	}

	return append(dst, attribute.KeyValue{Key: attribute.Key(key), Value: attributeValue(attr.Value)})
}

func attributeValue(value slog.Value) attribute.Value {
	switch value.Kind() {
	case slog.KindString:
		return attribute.StringValue(value.String())
	case slog.KindInt64:
		return attribute.Int64Value(value.Int64())
	case slog.KindUint64:
		if value.Uint64() > uint64(^uint64(0)>>1) {
			return attribute.StringValue(strconv.FormatUint(value.Uint64(), 10))
		}
		return attribute.Int64Value(int64(value.Uint64()))
	case slog.KindFloat64:
		return attribute.Float64Value(value.Float64())
	case slog.KindBool:
		return attribute.BoolValue(value.Bool())
	case slog.KindDuration:
		return attribute.StringValue(value.Duration().String())
	case slog.KindTime:
		return attribute.StringValue(value.Time().UTC().Format(time.RFC3339Nano))
	case slog.KindAny:
		switch typed := value.Any().(type) {
		case error:
			return attribute.StringValue(typed.Error())
		case fmt.Stringer:
			return attribute.StringValue(typed.String())
		default:
			return attribute.StringValue(fmt.Sprint(typed))
		}
	default:
		return attribute.StringValue(value.String())
	}
}

func severity(level slog.Level) otellog.Severity {
	switch {
	case level < slog.LevelInfo:
		return otellog.SeverityDebug
	case level < slog.LevelWarn:
		return otellog.SeverityInfo
	case level < slog.LevelError:
		return otellog.SeverityWarn
	default:
		return otellog.SeverityError
	}
}

func parseLevel(level string) slog.Level {
	switch strings.ToLower(strings.TrimSpace(level)) {
	case "debug":
		return slog.LevelDebug
	case "warn", "warning":
		return slog.LevelWarn
	case "error":
		return slog.LevelError
	default:
		return slog.LevelInfo
	}
}

func parseKVList(value string) map[string]string {
	if strings.TrimSpace(value) == "" {
		return nil
	}

	result := make(map[string]string)
	for _, pair := range strings.Split(value, ",") {
		key, raw, ok := strings.Cut(strings.TrimSpace(pair), "=")
		if !ok || key == "" {
			continue
		}
		decoded, err := url.QueryUnescape(strings.TrimSpace(raw))
		if err != nil {
			decoded = strings.TrimSpace(raw)
		}
		result[strings.TrimSpace(key)] = decoded
	}

	return result
}

func resourceAttribute(key string) string {
	attributes := parseKVList(os.Getenv("OTEL_RESOURCE_ATTRIBUTES"))

	return attributes[key]
}

func firstNonEmpty(values ...string) string {
	for _, value := range values {
		if strings.TrimSpace(value) != "" {
			return strings.TrimSpace(value)
		}
	}

	return ""
}

func parseBool(value string) bool {
	parsed, err := strconv.ParseBool(strings.TrimSpace(value))

	return err == nil && parsed
}
