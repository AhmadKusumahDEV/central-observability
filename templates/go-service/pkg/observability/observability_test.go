package observability

import (
	"net/http/httptest"
	"testing"
)

func TestRouteFromRequestStripsMethod(t *testing.T) {
	request := httptest.NewRequest("GET", "/work", nil)
	request.Pattern = "GET /work"

	if got := routeFromRequest(request); got != "/work" {
		t.Fatalf("routeFromRequest() = %q, want /work", got)
	}
}

func TestSignalEndpointAppendsSignalPath(t *testing.T) {
	got, insecure, err := signalEndpoint("http://127.0.0.1:4318", "/v1/traces", false)
	if err != nil {
		t.Fatalf("signalEndpoint() error = %v", err)
	}
	if got != "http://127.0.0.1:4318/v1/traces" {
		t.Fatalf("signalEndpoint() = %q", got)
	}
	if !insecure {
		t.Fatal("signalEndpoint() should mark http as insecure")
	}
}

func TestConfigFromEnvKeepsZeroSampleRatio(t *testing.T) {
	t.Setenv("OTEL_TRACES_SAMPLER_ARG", "0")

	cfg := ConfigFromEnv()
	if cfg.SampleRatio != 0 {
		t.Fatalf("SampleRatio = %v, want 0", cfg.SampleRatio)
	}
	if !cfg.SampleRatioSet {
		t.Fatal("SampleRatioSet should be true when sampler arg is present")
	}
}
