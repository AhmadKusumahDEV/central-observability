$ErrorActionPreference = "Stop"

$root = (Resolve-Path (Join-Path $PSScriptRoot "..")).Path
Set-Location $root

$prometheus = "http://127.0.0.1:9090"
$grafana = "http://127.0.0.1:3000"
$loki = "http://127.0.0.1:3100"
$tempo = "http://127.0.0.1:3200"
$demo = "http://127.0.0.1:8080"

function Assert-True {
    param([bool]$Condition, [string]$Message)
    if (-not $Condition) {
        throw "FAIL: $Message"
    }
    Write-Host "PASS: $Message"
}

function Wait-Http {
    param([string]$Name, [string]$Uri)
    for ($i = 0; $i -lt 40; $i++) {
        try {
            Invoke-WebRequest -Uri $Uri -UseBasicParsing -TimeoutSec 3 | Out-Null
            return
        } catch {
            Start-Sleep -Seconds 2
        }
    }
    throw "FAIL: $Name did not become ready"
}

Write-Host "Starting the demo profile..."
docker compose --profile demo up -d --build

Write-Host "Waiting for services..."
Wait-Http Grafana "$grafana/api/health"
Wait-Http Prometheus "$prometheus/-/ready"
Wait-Http Tempo "$tempo/ready"
Wait-Http Loki "$loki/ready"
Wait-Http Alertmanager "http://127.0.0.1:9093/-/ready"
Wait-Http "Demo app" "$demo/healthz"

Write-Host "Generating requests..."
for ($i = 1; $i -le 15; $i++) {
    Invoke-WebRequest -Uri "$demo/work" -UseBasicParsing | Out-Null
    try {
        Invoke-WebRequest -Uri "$demo/error" -UseBasicParsing | Out-Null
    } catch {
    }
    Invoke-WebRequest -Uri "$demo/slow?delay_ms=700" -UseBasicParsing | Out-Null
}

Write-Host "Waiting for OTLP batching and backend ingestion..."
Start-Sleep -Seconds 30

$promQuery = [Uri]::EscapeDataString('http_server_request_duration_seconds_count{service_name="go-demo", deployment_environment_name="dev"}')
$promJson = Invoke-RestMethod -Uri "$prometheus/api/v1/query?query=$promQuery"
$promRaw = $promJson | ConvertTo-Json -Depth 20 -Compress
Assert-True ($promRaw -match '"service_name":"go-demo"') "metrics reached Prometheus"

$lokiQuery = [Uri]::EscapeDataString('{service_name="go-demo", deployment_environment_name="dev"}')
$lokiJson = Invoke-RestMethod -Uri "$loki/loki/api/v1/query_range?query=$lokiQuery&limit=10"
$lokiRaw = $lokiJson | ConvertTo-Json -Depth 20 -Compress
Assert-True ($lokiRaw -match '"values":\[\[') "logs reached Loki"

$tempoQuery = [Uri]::EscapeDataString('{ resource.service.name="go-demo" }')
$tempoJson = Invoke-RestMethod -Uri "$tempo/api/search?q=$tempoQuery&limit=10"
$tempoRaw = $tempoJson | ConvertTo-Json -Depth 20 -Compress
Assert-True ($tempoRaw -match '"traceID"') "traces reached Tempo"

$rulesRaw = Invoke-RestMethod -Uri "$prometheus/api/v1/rules" |
    ConvertTo-Json -Depth 30 -Compress
Assert-True ($rulesRaw -match 'ServiceHighErrorRate') "error-rate alert rule loaded"
Assert-True ($rulesRaw -match 'ServiceNoHeartbeat') "heartbeat alert rule loaded"

$password = (Get-Content -LiteralPath "secrets/grafana_admin_password" -Raw).Trim()
$pair = "admin:$password"
$headers = @{ Authorization = "Basic " + [Convert]::ToBase64String([Text.Encoding]::ASCII.GetBytes($pair)) }
$dashboards = Invoke-RestMethod -Uri "$grafana/api/search?query=Service%20Overview" -Headers $headers
$dashboardRaw = $dashboards | ConvertTo-Json -Depth 10 -Compress
Assert-True ($dashboardRaw -match 'central-service-overview') "Grafana dashboard provisioned"

Write-Host ""
Write-Host "All automated smoke checks passed."
Write-Host "Open $grafana/d/central-service-overview and set Service=go-demo, Environment=dev."
Write-Host "Click TraceID in the recent error logs panel to verify log-to-trace correlation."

