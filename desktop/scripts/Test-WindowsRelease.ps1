[CmdletBinding()]
param(
    [Parameter(Mandatory = $true)]
    [string]$PublishDirectory,
    [switch]$AsJson
)

$ErrorActionPreference = 'Stop'
$PublishDirectory = [System.IO.Path]::GetFullPath($PublishDirectory)
$manifestPath = Join-Path $PublishDirectory 'semyra-release.json'
$desktopPath = Join-Path $PublishDirectory 'Semyra.Desktop.exe'
$mediaHostPath = Join-Path $PublishDirectory 'Semyra.MediaHost.exe'
$desktopRuntimeConfig = Join-Path $PublishDirectory 'Semyra.Desktop.runtimeconfig.json'
$desktopDepsPath = Join-Path $PublishDirectory 'Semyra.Desktop.deps.json'
$winRtRuntimePath = Join-Path $PublishDirectory 'WinRT.Runtime.dll'
$winRtHostShimPath = Join-Path $PublishDirectory 'WinRT.Host.Shim.dll'

$manifest = if (Test-Path -LiteralPath $manifestPath -PathType Leaf) {
    Get-Content -Raw -LiteralPath $manifestPath | ConvertFrom-Json
} else { $null }

$configurationOnline = $null -ne $manifest `
    -and $manifest.officialOrigin -eq 'https://semyra.jzlcode.com.br/' `
    -and $manifest.runtimeIdentifier -eq 'win-x64'

$selfContained = $false
if (Test-Path -LiteralPath $desktopRuntimeConfig -PathType Leaf) {
    $runtimeConfig = Get-Content -Raw -LiteralPath $desktopRuntimeConfig | ConvertFrom-Json
    $selfContained = $null -ne $runtimeConfig.runtimeOptions.includedFrameworks
}

$winRtDependencyReady = $false
if ((Test-Path -LiteralPath $desktopDepsPath -PathType Leaf) `
    -and (Test-Path -LiteralPath $winRtRuntimePath -PathType Leaf) `
    -and (Test-Path -LiteralPath $winRtHostShimPath -PathType Leaf)) {
    $desktopDeps = Get-Content -Raw -LiteralPath $desktopDepsPath
    $winRtDependencyReady = $desktopDeps -match 'Microsoft\.Windows\.CsWinRT/1\.6\.5' `
        -and $desktopDeps -match 'lib/net5\.0/WinRT\.Runtime\.dll' `
        -and $desktopDeps -match 'lib/net5\.0/WinRT\.Host\.Shim\.dll'
}

$webViewVersion = $null
$webViewRoots = @(
    (Join-Path ${env:ProgramFiles(x86)} 'Microsoft\EdgeWebView\Application'),
    (Join-Path $env:LOCALAPPDATA 'Microsoft\EdgeWebView\Application')
)
foreach ($root in $webViewRoots) {
    if ([string]::IsNullOrWhiteSpace($root) -or -not (Test-Path -LiteralPath $root -PathType Container)) { continue }
    $candidate = Get-ChildItem -LiteralPath $root -Directory -ErrorAction SilentlyContinue |
        Where-Object { Test-Path -LiteralPath (Join-Path $_.FullName 'msedgewebview2.exe') -PathType Leaf } |
        Sort-Object Name -Descending |
        Select-Object -First 1
    if ($null -ne $candidate) { $webViewVersion = $candidate.Name; break }
}

$mpvPath = Join-Path $PublishDirectory 'runtime\mpv\mpv.exe'
$gstBin = Join-Path $PublishDirectory 'runtime\gstreamer\bin'
$gstLaunch = Join-Path $gstBin 'gst-launch-1.0.exe'
$gstInspect = Join-Path $gstBin 'gst-inspect-1.0.exe'
$gstreamerAvailable = (Test-Path -LiteralPath $gstLaunch -PathType Leaf) -and (Test-Path -LiteralPath $gstInspect -PathType Leaf)
$whipAvailable = $false
if ($gstreamerAvailable) {
    & $gstInspect whipsink *> $null
    $whipAvailable = $LASTEXITCODE -eq 0
}

$result = [ordered]@{
    desktop = if (Test-Path -LiteralPath $desktopPath -PathType Leaf) { 'ready' } else { 'unavailable' }
    webView2 = if ($null -ne $webViewVersion) { 'ready' } else { 'unavailable' }
    webView2Version = $webViewVersion
    mpv = if (Test-Path -LiteralPath $mpvPath -PathType Leaf) { 'ready' } else { 'unavailable' }
    gstreamer = if ($gstreamerAvailable) { 'ready' } else { 'unavailable' }
    whip = if ($whipAvailable) { 'ready' } else { 'unavailable' }
    mediaHost = if (Test-Path -LiteralPath $mediaHostPath -PathType Leaf) { 'ready' } else { 'unavailable' }
    winRtRuntime = if ($winRtDependencyReady) { 'ready' } else { 'invalid' }
    onlineConfiguration = if ($configurationOnline) { 'ready' } else { 'invalid' }
    dotNetSelfContained = $selfContained
    desktopReady = (Test-Path -LiteralPath $desktopPath -PathType Leaf) `
        -and (Test-Path -LiteralPath $mediaHostPath -PathType Leaf) `
        -and $winRtDependencyReady `
        -and $null -ne $webViewVersion `
        -and $configurationOnline `
        -and $selfContained
}

if ($AsJson) {
    $result | ConvertTo-Json
}
else {
    $result.GetEnumerator() | ForEach-Object { '{0}: {1}' -f $_.Key, $_.Value }
}

if (-not $result.desktopReady) { exit 1 }
