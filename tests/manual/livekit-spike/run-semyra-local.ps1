[CmdletBinding()]
param([int] $Port = 8092)

$ErrorActionPreference = 'Stop'
$root = Resolve-Path (Join-Path $PSScriptRoot '..\..\..')
$privateEnv = Join-Path $PSScriptRoot '.private\livekit.env'
if (-not (Test-Path -LiteralPath $privateEnv)) {
    throw 'Private LiveKit environment is unavailable.'
}

$required = @('LIVEKIT_URL', 'LIVEKIT_API_KEY', 'LIVEKIT_API_SECRET')
$values = @{}
foreach ($line in Get-Content -LiteralPath $privateEnv) {
    if ($line -match '^([A-Z][A-Z0-9_]*)=(.*)$') {
        $values[$Matches[1]] = $Matches[2].Trim().Trim('"').Trim("'")
    }
}
foreach ($name in $required) {
    if (-not $values.ContainsKey($name) -or [string]::IsNullOrWhiteSpace($values[$name])) {
        throw "Missing $name in the private environment."
    }
    [Environment]::SetEnvironmentVariable($name, $values[$name], 'Process')
}
[Environment]::SetEnvironmentVariable('LIVEKIT_ENABLED', 'true', 'Process')
[Environment]::SetEnvironmentVariable('LIVEKIT_NAMESPACE', 'local', 'Process')
[Environment]::SetEnvironmentVariable('APP_ENV', 'local', 'Process')

Push-Location $root
try {
    & php -S "127.0.0.1:$Port" -t public bin/server-router.php
} finally {
    Pop-Location
}
