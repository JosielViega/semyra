[CmdletBinding()]
param(
    [Parameter(Mandatory = $true)]
    [string]$PublishDirectory,
    [switch]$AsJson
)

$ErrorActionPreference = 'Stop'
$PublishDirectory = [System.IO.Path]::GetFullPath($PublishDirectory)
$manifestPath = Join-Path $PublishDirectory 'semyra-release.json'
$dependencyManifestPath = Join-Path $PublishDirectory 'semyra-medialab-dependencies.json'
$mpvPath = Join-Path $PublishDirectory 'runtime\mpv\mpv.exe'
$gstRoot = Join-Path $PublishDirectory 'runtime\gstreamer'
$gstBin = Join-Path $gstRoot 'bin'
$gstLaunch = Join-Path $gstBin 'gst-launch-1.0.exe'
$gstInspect = Join-Path $gstBin 'gst-inspect-1.0.exe'
$gstPluginRoot = Join-Path $gstRoot 'lib\gstreamer-1.0'
$gstPluginScanner = Join-Path $gstRoot 'libexec\gstreamer-1.0\gst-plugin-scanner.exe'

function Invoke-NativeCaptured([string]$FilePath, [string[]]$Arguments) {
    $startInfo = New-Object System.Diagnostics.ProcessStartInfo
    $startInfo.FileName = $FilePath
    $startInfo.Arguments = $Arguments -join ' '
    $startInfo.UseShellExecute = $false
    $startInfo.CreateNoWindow = $true
    $startInfo.RedirectStandardOutput = $true
    $startInfo.RedirectStandardError = $true
    $process = New-Object System.Diagnostics.Process
    $process.StartInfo = $startInfo
    if (-not $process.Start()) { throw "Falha ao iniciar: $FilePath" }
    $stdout = $process.StandardOutput.ReadToEnd()
    $stderr = $process.StandardError.ReadToEnd()
    $process.WaitForExit()
    [pscustomobject]@{ ExitCode = $process.ExitCode; Output = ($stdout + $stderr).Trim() }
}

foreach ($path in @($manifestPath, $dependencyManifestPath, $mpvPath, $gstLaunch, $gstInspect, $gstPluginScanner)) {
    if (-not (Test-Path -LiteralPath $path -PathType Leaf)) {
        throw "Arquivo obrigatorio ausente do MediaLab: $path"
    }
}

$manifest = Get-Content -Raw -LiteralPath $manifestPath | ConvertFrom-Json
if ($manifest.product -ne 'Semyra MediaLab Preview' `
    -or $manifest.version -ne '0.15.0-preview.1' `
    -or $manifest.runtimeIdentifier -ne 'win-x64' `
    -or -not $manifest.selfContained `
    -or -not $manifest.nativeMediaRuntimesIncluded `
    -or $manifest.gstreamerPackaging -ne 'full' `
    -or $manifest.publicDistributionAllowed) {
    throw 'Manifesto MediaLab invalido.'
}

$privatePatterns = @('*.db', '*.sqlite', '*.m3u', '*.m3u8', '.env', '.env.*', '*.log', '*.pem', '*.key', '*.pfx', '*.p12')
$privateFiles = foreach ($pattern in $privatePatterns) {
    Get-ChildItem -LiteralPath $PublishDirectory -Recurse -Force -File -Filter $pattern -ErrorAction SilentlyContinue
}
if (@($privateFiles).Count -gt 0) {
    throw "Arquivos privados encontrados: $(@($privateFiles).FullName -join ', ')"
}

$oldEnvironment = @{}
foreach ($name in @('PATH', 'GST_PLUGIN_SYSTEM_PATH_1_0', 'GST_PLUGIN_PATH_1_0', 'GST_PLUGIN_SCANNER', 'GST_REGISTRY')) {
    $oldEnvironment[$name] = [Environment]::GetEnvironmentVariable($name, 'Process')
}
$registryPath = Join-Path $env:TEMP ('semyra-medialab-gst-' + [guid]::NewGuid().ToString('N') + '.bin')

try {
    $env:PATH = $gstBin + ';' + (Join-Path $env:SystemRoot 'System32')
    $env:GST_PLUGIN_SYSTEM_PATH_1_0 = $gstPluginRoot
    $env:GST_PLUGIN_PATH_1_0 = ''
    $env:GST_PLUGIN_SCANNER = $gstPluginScanner
    $env:GST_REGISTRY = $registryPath

    $mpvResult = Invoke-NativeCaptured $mpvPath @('--version')
    $mpvVersion = ($mpvResult.Output -split "`r?`n" | Select-Object -First 1) -as [string]
    if ($mpvResult.ExitCode -ne 0 -or $mpvVersion -notmatch 'v0\.41\.0-1102-g6c092d978') {
        throw "MPV app-relative invalido: $mpvVersion"
    }

    $gstLaunchResult = Invoke-NativeCaptured $gstLaunch @('--version')
    $gstLaunchVersion = (($gstLaunchResult.Output -split "`r?`n") | Select-Object -First 2) -join ' '
    if ($gstLaunchResult.ExitCode -ne 0 -or $gstLaunchVersion -notmatch '1\.26\.11') {
        throw "gst-launch app-relative invalido: $gstLaunchVersion"
    }
    $gstInspectResult = Invoke-NativeCaptured $gstInspect @('--version')
    $gstInspectVersion = (($gstInspectResult.Output -split "`r?`n") | Select-Object -First 2) -join ' '
    if ($gstInspectResult.ExitCode -ne 0 -or $gstInspectVersion -notmatch '1\.26\.11') {
        throw "gst-inspect app-relative invalido: $gstInspectVersion"
    }

    $requiredElements = @(
        'fdsrc', 'queue', 'tsdemux', 'h264parse', 'avdec_h264', 'videoconvert',
        'x264enc', 'rtph264pay', 'aacparse', 'avdec_aac', 'audioconvert',
        'audioresample', 'opusenc', 'rtpopuspay', 'whipsink', 'souphttpsrc',
        'qtdemux', 'clocksync', 'identity', 'intervideosrc', 'intervideosink',
        'interaudiosrc', 'interaudiosink', 'd3d11screencapturesrc',
        'd3d11download', 'videoscale', 'videorate', 'wasapi2src'
    )
    $missingElements = @()
    foreach ($element in $requiredElements) {
        $inspection = Invoke-NativeCaptured $gstInspect @($element)
        if ($inspection.ExitCode -ne 0) { $missingElements += $element }
    }
    $screenEncoderAvailable = $false
    foreach ($encoder in @('nvh264enc', 'mfh264enc', 'x264enc')) {
        $inspection = Invoke-NativeCaptured $gstInspect @($encoder)
        if ($inspection.ExitCode -eq 0) { $screenEncoderAvailable = $true; break }
    }
    if ($missingElements.Count -gt 0 -or -not $screenEncoderAvailable) {
        throw "Plugins GStreamer ausentes: $($missingElements -join ', '); screen encoder=$screenEncoderAvailable"
    }
}
finally {
    foreach ($name in $oldEnvironment.Keys) {
        [Environment]::SetEnvironmentVariable($name, $oldEnvironment[$name], 'Process')
    }
    if (Test-Path -LiteralPath $registryPath) {
        Remove-Item -LiteralPath $registryPath -Force
    }
}

$files = @(Get-ChildItem -LiteralPath $PublishDirectory -Recurse -File)
$result = [ordered]@{
    publishDirectory = $PublishDirectory
    product = $manifest.product
    version = $manifest.version
    fileCount = $files.Count
    totalBytes = ($files | Measure-Object -Property Length -Sum).Sum
    mpvVersion = $mpvVersion
    gstreamerVersion = '1.26.11'
    gstreamerPackaging = $manifest.gstreamerPackaging
    requiredPluginCount = $requiredElements.Count
    requiredPlugins = 'ready'
    screenEncoder = 'ready'
    privateFiles = 'absent'
    processIsolatedValidation = 'passed'
    publicDistributionAllowed = $false
    validation = 'passed'
}

if ($AsJson) { $result | ConvertTo-Json -Depth 4 }
else { $result.GetEnumerator() | ForEach-Object { '{0}: {1}' -f $_.Key, $_.Value } }
