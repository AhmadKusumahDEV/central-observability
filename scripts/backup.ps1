$ErrorActionPreference = "Stop"

$root = (Resolve-Path (Join-Path $PSScriptRoot "..")).Path
Set-Location $root

$timestamp = (Get-Date).ToUniversalTime().ToString("yyyyMMddTHHmmssZ")
$backupDir = Join-Path $root "backups\$timestamp"
New-Item -ItemType Directory -Force -Path $backupDir | Out-Null

$runningServices = @(docker compose ps --status running --services)
$started = $false
$startedAt = Get-Date

try {
    Write-Host "Stopping writers for a consistent cold backup..."
    docker compose stop prometheus loki tempo grafana alertmanager
    $started = $true

    $volumes = @(
        @("central-observability-prometheus-data", "prometheus"),
        @("central-observability-loki-data", "loki"),
        @("central-observability-tempo-data", "tempo"),
        @("central-observability-grafana-data", "grafana"),
        @("central-observability-alertmanager-data", "alertmanager")
    )

    $previousErrorAction = $ErrorActionPreference
    $ErrorActionPreference = "Continue"
    docker volume inspect central-observability-caddy-data *> $null
    $caddyVolumeExists = ($LASTEXITCODE -eq 0)
    $ErrorActionPreference = $previousErrorAction
    if ($caddyVolumeExists) {
        $volumes += ,@("central-observability-caddy-data", "caddy")
    }

    foreach ($entry in $volumes) {
        $volume = $entry[0]
        $name = $entry[1]
        docker run --rm --volume "$($volume):/source:ro" --volume "$($backupDir):/backup" alpine:3.23.7 sh -c "tar -C /source -czf /backup/$name.tgz ."
        if ($LASTEXITCODE -ne 0) {
            throw "backup failed for $volume"
        }
        Write-Host "backed up $volume"
    }

    $elapsed = [math]::Round(((Get-Date) - $startedAt).TotalSeconds)
    @(
        "created_utc=$timestamp"
        "cold_backup_seconds=$elapsed"
        "note=Secrets are not included. Back them up separately with encryption."
    ) | Set-Content -LiteralPath (Join-Path $backupDir "MANIFEST.txt")

    Write-Host "Backup completed at $backupDir"
} finally {
    if ($started -and $runningServices.Count -gt 0) {
        docker compose start @runningServices
    }
}

