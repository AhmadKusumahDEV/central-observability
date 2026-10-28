$ErrorActionPreference = "Stop"

$root = (Resolve-Path (Join-Path $PSScriptRoot "..")).Path
Set-Location $root
$secretDir = Join-Path $root "secrets"
New-Item -ItemType Directory -Force -Path $secretDir | Out-Null

function New-RandomSecret {
    param([string]$Path)
    if ((Test-Path $Path) -and ((Get-Item -LiteralPath $Path).Length -gt 0)) {
        Write-Host "keep existing $Path"
        return
    }
    $bytes = New-Object byte[] 32
    $rng = [System.Security.Cryptography.RandomNumberGenerator]::Create()
    try {
        $rng.GetBytes($bytes)
    } finally {
        $rng.Dispose()
    }
    $value = [Convert]::ToBase64String($bytes).TrimEnd("=").Replace("+", "-").Replace("/", "_")
    Set-Content -LiteralPath $Path -Value $value -NoNewline -Encoding ascii
    Write-Host "created $Path"
}

New-RandomSecret (Join-Path $secretDir "grafana_admin_password")

$telegramToken = Join-Path $secretDir "telegram_bot_token"
if (-not (Test-Path $telegramToken)) {
    Set-Content -LiteralPath $telegramToken -Value "REPLACE_WITH_TELEGRAM_BOT_TOKEN" -NoNewline -Encoding ascii
    Write-Host "created $telegramToken placeholder"
}

$telegramChat = Join-Path $secretDir "telegram_chat_id"
if (-not (Test-Path $telegramChat)) {
    Set-Content -LiteralPath $telegramChat -Value "0" -NoNewline -Encoding ascii
    Write-Host "created $telegramChat placeholder"
}

$envFile = Join-Path $root ".env"
if (-not (Test-Path $envFile)) {
    Copy-Item -LiteralPath (Join-Path $root ".env.example") -Destination $envFile
    Write-Host "created .env from .env.example"
}

$network = "monitoring"
$networkLine = Get-Content -LiteralPath $envFile |
    Where-Object { $_ -match "^MONITORING_NETWORK=" } |
    Select-Object -First 1
if ($networkLine) {
    $network = $networkLine.Substring("MONITORING_NETWORK=".Length).Trim()
}

$previousErrorAction = $ErrorActionPreference
$ErrorActionPreference = "Continue"
docker info *> $null
$dockerAvailable = ($LASTEXITCODE -eq 0)
if ($dockerAvailable) {
    docker network inspect $network *> $null
    if ($LASTEXITCODE -ne 0) {
        docker network create $network | Out-Null
        Write-Host "created Docker network $network"
    } else {
        Write-Host "Docker network $network already exists"
    }
} else {
    Write-Warning "Docker daemon is not running; create network '$network' before starting the stack."
}
$ErrorActionPreference = $previousErrorAction

Write-Host ""
Write-Host "Grafana admin password:"
Write-Host "  Get-Content secrets/grafana_admin_password"
Write-Host "Set a real Telegram token and chat ID in secrets/ if you want notifications."

