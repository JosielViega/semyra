[CmdletBinding()]
param(
    [Parameter(Mandatory = $true)]
    [ValidateSet('start', 'health', 'stop')]
    [string] $Action,
    [int] $Port = 8092
)

$ErrorActionPreference = 'Stop'
$root = Resolve-Path (Join-Path $PSScriptRoot '..\..\..')
$privateDirectory = Join-Path $PSScriptRoot '.private'
$privateEnv = Join-Path $privateDirectory 'livekit.env'
$pidPath = Join-Path $privateDirectory 'managed-smoke-server.pid'
$stdoutPath = Join-Path $privateDirectory 'managed-smoke-server.out'
$stderrPath = Join-Path $privateDirectory 'managed-smoke-server.err'
$healthUrl = "http://127.0.0.1:$Port/health"

function Test-SemyraHealth {
    try {
        $response = Invoke-WebRequest -UseBasicParsing -Uri $healthUrl -TimeoutSec 2
        return $response.StatusCode -eq 200
    } catch {
        return $false
    }
}

if ($Action -eq 'health') {
    if (Test-SemyraHealth) {
        Write-Output 'Semyra local health: passed'
        exit 0
    }
    Write-Error 'Semyra local health: failed'
    exit 1
}

if ($Action -eq 'stop') {
    if (-not (Test-Path -LiteralPath $pidPath)) {
        Write-Output 'Semyra local server: not running'
        exit 0
    }
    $serverPid = [int] (Get-Content -LiteralPath $pidPath -Raw)
    $process = Get-CimInstance Win32_Process -Filter "ProcessId = $serverPid" -ErrorAction SilentlyContinue
    if ($null -ne $process) {
        $expectedRouter = 'bin/server-router.php'
        if ($process.Name -notmatch '^php(\.exe)?$' -or
            $process.CommandLine -notlike "*127.0.0.1:$Port*" -or
            $process.CommandLine -notlike "*$expectedRouter*") {
            throw 'Refusing to stop an unexpected process.'
        }
        Stop-Process -Id $serverPid -Force
    }
    Remove-Item -LiteralPath $pidPath -Force
    Write-Output 'Semyra local server: stopped'
    exit 0
}

if (Test-Path -LiteralPath $pidPath) {
    throw 'Managed smoke server PID file already exists.'
}
if (-not (Test-Path -LiteralPath $privateEnv)) {
    throw 'Private LiveKit environment is unavailable.'
}
if ($env:MEDIA_BRIDGE_ENABLED -ne 'true' -or
    $env:MEDIA_BRIDGE_WORKER_SECRET -notmatch '^[a-f0-9]{64}$') {
    throw 'Private media bridge environment is unavailable.'
}

$values = @{}
foreach ($line in Get-Content -LiteralPath $privateEnv) {
    if ($line -match '^([A-Z][A-Z0-9_]*)=(.*)$') {
        $values[$Matches[1]] = $Matches[2].Trim().Trim('"').Trim("'")
    }
}
foreach ($name in @('LIVEKIT_URL', 'LIVEKIT_API_KEY', 'LIVEKIT_API_SECRET')) {
    if (-not $values.ContainsKey($name) -or [string]::IsNullOrWhiteSpace($values[$name])) {
        throw "Missing $name in the private environment."
    }
    [Environment]::SetEnvironmentVariable($name, $values[$name], 'Process')
}
[Environment]::SetEnvironmentVariable('LIVEKIT_ENABLED', 'true', 'Process')
[Environment]::SetEnvironmentVariable('LIVEKIT_NAMESPACE', 'local', 'Process')
[Environment]::SetEnvironmentVariable('APP_ENV', 'local', 'Process')

$server = Start-Process -FilePath 'php' -ArgumentList @(
    '-S', "127.0.0.1:$Port", '-t', 'public', 'bin/server-router.php'
) -WorkingDirectory $root -RedirectStandardOutput $stdoutPath -RedirectStandardError $stderrPath -WindowStyle Hidden -PassThru
Set-Content -LiteralPath $pidPath -Value $server.Id -NoNewline

$deadline = (Get-Date).AddSeconds(15)
do {
    if (Test-SemyraHealth) {
        Write-Output 'Semyra local server: started and healthy'
        exit 0
    }
    if ($server.HasExited) {
        break
    }
    Start-Sleep -Milliseconds 250
} while ((Get-Date) -lt $deadline)

if (-not $server.HasExited) {
    Stop-Process -Id $server.Id -Force
}
Remove-Item -LiteralPath $pidPath -Force -ErrorAction SilentlyContinue
throw 'Semyra local server failed its startup health check.'
