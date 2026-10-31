package main

import (
	"context"
	"database/sql"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"log/slog"
	"net/http"
	"os"
	"os/signal"
	"strconv"
	"strings"
	"syscall"
	"time"

	"example.com/go-service-template/pkg/observability"
	"github.com/XSAM/otelsql"
	"go.opentelemetry.io/otel/codes"
	"go.opentelemetry.io/otel/metric"
	"go.opentelemetry.io/otel/trace"
	_ "modernc.org/sqlite"
)

type application struct {
	stack       *observability.Stack
	logger      *slog.Logger
	db          *sql.DB
	client      *http.Client
	downstream  string
	workCounter metric.Int64Counter
}

func main() {
	ctx, stop := signal.NotifyContext(context.Background(), os.Interrupt, syscall.SIGTERM)
	defer stop()

	app, err := newApplication(ctx)
	if err != nil {
		fmt.Fprintf(os.Stderr, "startup: %v\n", err)
		os.Exit(1)
	}

	mux := http.NewServeMux()
	mux.HandleFunc("GET /healthz", func(w http.ResponseWriter, _ *http.Request) {
		w.Header().Set("Content-Type", "text/plain; charset=utf-8")
		_, _ = w.Write([]byte("ok\n"))
	})
	mux.HandleFunc("GET /downstream", func(w http.ResponseWriter, _ *http.Request) {
		writeJSON(w, http.StatusOK, map[string]string{"status": "ok"})
	})
	mux.HandleFunc("GET /work", app.handleWork)
	mux.HandleFunc("GET /error", app.handleError)
	mux.HandleFunc("GET /slow", app.handleSlow)

	server := &http.Server{
		Addr:              env("APP_ADDR", ":8080"),
		Handler:           app.stack.HandlerWithRoute(mux, routeForRequest(mux)),
		ReadHeaderTimeout: 5 * time.Second,
	}

	go func() {
		app.logger.InfoContext(ctx, "http server listening", "address", server.Addr)
		if err := server.ListenAndServe(); err != nil && !errors.Is(err, http.ErrServerClosed) {
			app.logger.ErrorContext(ctx, "http server stopped", "error", err)
			stop()
		}
	}()

	<-ctx.Done()
	serverCtx, cancelServer := context.WithTimeout(context.Background(), 10*time.Second)
	if err := server.Shutdown(serverCtx); err != nil {
		app.logger.ErrorContext(serverCtx, "server shutdown", "error", err)
	}
	cancelServer()

	telemetryCtx, cancelTelemetry := context.WithTimeout(context.Background(), 10*time.Second)
	if err := app.stack.Shutdown(telemetryCtx); err != nil {
		app.logger.ErrorContext(telemetryCtx, "telemetry shutdown", "error", err)
	}
	cancelTelemetry()
}

func newApplication(ctx context.Context) (*application, error) {
	stack, err := observability.Setup(ctx, observability.ConfigFromEnv())
	if err != nil {
		return nil, fmt.Errorf("setup observability: %w", err)
	}

	database, err := otelsql.Open("sqlite", "file:template?mode=memory&cache=shared")
	if err != nil {
		_ = stack.Shutdown(context.Background())
		return nil, fmt.Errorf("open sqlite: %w", err)
	}
	database.SetMaxOpenConns(1)
	if _, err := database.ExecContext(ctx,
		"CREATE TABLE IF NOT EXISTS work_items (id INTEGER PRIMARY KEY AUTOINCREMENT, created_at TEXT NOT NULL)",
	); err != nil {
		_ = database.Close()
		_ = stack.Shutdown(context.Background())
		return nil, fmt.Errorf("create table: %w", err)
	}

	app := &application{
		stack:      stack,
		logger:     stack.Logger,
		db:         database,
		client:     stack.HTTPClient(nil),
		downstream: env("DOWNSTREAM_URL", "http://127.0.0.1:8080/downstream"),
	}
	app.workCounter, err = stack.Meter.Int64Counter(
		"app.work.completed",
		metric.WithDescription("Jumlah pekerjaan yang selesai"),
	)
	if err != nil {
		_ = database.Close()
		_ = stack.Shutdown(context.Background())
		return nil, fmt.Errorf("create work counter: %w", err)
	}

	return app, nil
}

func (a *application) handleWork(w http.ResponseWriter, r *http.Request) {
	ctx, span := a.stack.Tracer.Start(r.Context(), "work.process")
	defer span.End()

	if _, err := a.db.ExecContext(ctx, "INSERT INTO work_items (created_at) VALUES (?)", time.Now().UTC().Format(time.RFC3339Nano)); err != nil {
		a.fail(w, ctx, span, fmt.Errorf("insert work item: %w", err))
		return
	}
	if err := a.callDownstream(ctx); err != nil {
		a.fail(w, ctx, span, fmt.Errorf("call downstream: %w", err))
		return
	}
	a.workCounter.Add(ctx, 1)

	a.logger.InfoContext(ctx, "work completed", "work.item", "example")
	writeJSON(w, http.StatusOK, map[string]string{
		"status":   "ok",
		"trace_id": trace.SpanContextFromContext(ctx).TraceID().String(),
	})
}

func (a *application) handleError(w http.ResponseWriter, r *http.Request) {
	ctx, span := a.stack.Tracer.Start(r.Context(), "work.fail")
	defer span.End()
	a.fail(w, ctx, span, errors.New("contoh kegagalan untuk menguji alert"))
}

func (a *application) handleSlow(w http.ResponseWriter, r *http.Request) {
	delay := 900 * time.Millisecond
	if raw := r.URL.Query().Get("delay_ms"); raw != "" {
		if milliseconds, err := strconv.Atoi(raw); err == nil && milliseconds > 0 && milliseconds <= 5000 {
			delay = time.Duration(milliseconds) * time.Millisecond
		}
	}
	time.Sleep(delay)
	writeJSON(w, http.StatusOK, map[string]any{
		"status":      "ok",
		"duration_ms": delay.Milliseconds(),
		"trace_id":    trace.SpanContextFromContext(r.Context()).TraceID().String(),
	})
}

func (a *application) callDownstream(ctx context.Context) error {
	request, err := http.NewRequestWithContext(ctx, http.MethodGet, a.downstream, nil)
	if err != nil {
		return err
	}
	response, err := a.client.Do(request)
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

func (a *application) fail(w http.ResponseWriter, ctx context.Context, span trace.Span, err error) {
	span.RecordError(err)
	span.SetStatus(codes.Error, err.Error())
	a.logger.ErrorContext(ctx, "request failed", "error", err)
	writeJSON(w, http.StatusInternalServerError, map[string]string{
		"error":    err.Error(),
		"trace_id": trace.SpanContextFromContext(ctx).TraceID().String(),
	})
}

func writeJSON(w http.ResponseWriter, status int, value any) {
	w.Header().Set("Content-Type", "application/json")
	w.WriteHeader(status)
	_ = json.NewEncoder(w).Encode(value)
}

func env(key, fallback string) string {
	if value := os.Getenv(key); value != "" {
		return value
	}

	return fallback
}

func routeForRequest(mux *http.ServeMux) func(*http.Request) string {
	return func(r *http.Request) string {
		_, pattern := mux.Handler(r)
		if _, path, ok := strings.Cut(pattern, " "); ok {
			return path
		}
		return pattern
	}
}
