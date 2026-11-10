[CmdletBinding()]
param(
    [string]$OutputDirectory,
    [string]$MpvSourceDirectory,
    [string]$GStreamerSourceDirectory,
    [string]$GStreamerSourceMsi
)

$ErrorActionPreference = 'Stop'
$repositoryRoot = [System.IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..\..'))
$project = Join-Path $repositoryRoot 'desktop\src\Semyra.Desktop\Semyra.Desktop.csproj'
$profile = 'WindowsX64'
$releaseRoot = [System.IO.Path]::GetFullPath((Join-Path $repositoryRoot 'storage\cache\releases'))

if ([string]::IsNullOrWhiteSpace($OutputDirectory)) {
    $OutputDirectory = Join-Path $releaseRoot 'semyra-desktop-0.15.0-preview.1-MediaLab-win-x64'
}
if ([string]::IsNullOrWhiteSpace($MpvSourceDirectory)) {
    $MpvSourceDirectory = 'C:\PROJETOS\semyra\storage\cache\semyra-mpv-integration\runtime\mpv'
}
if ([string]::IsNullOrWhiteSpace($GStreamerSourceDirectory)) {
    $GStreamerSourceDirectory = 'C:\PROJETOS\semyra\storage\cache\gstreamer-1.26.11-runtime\PFiles64\gstreamer\1.0\msvc_x86_64'
}
if ([string]::IsNullOrWhiteSpace($GStreamerSourceMsi)) {
    $GStreamerSourceMsi = 'C:\PROJETOS\semyra\storage\cache\gstreamer-1.0-msvc-x86_64-1.26.11.msi'
}

$OutputDirectory = [System.IO.Path]::GetFullPath($OutputDirectory)
$MpvSourceDirectory = [System.IO.Path]::GetFullPath($MpvSourceDirectory)
$GStreamerSourceDirectory = [System.IO.Path]::GetFullPath($GStreamerSourceDirectory)
$GStreamerSourceMsi = [System.IO.Path]::GetFullPath($GStreamerSourceMsi)

if (-not $OutputDirectory.StartsWith($releaseRoot + [System.IO.Path]::DirectorySeparatorChar, [System.StringComparison]::OrdinalIgnoreCase)) {
    throw 'A saida MediaLab deve ficar dentro de storage\cache\releases.'
}
if (Test-Path -LiteralPath $OutputDirectory) {
    throw "A saida MediaLab ja existe e nao sera sobrescrita: $OutputDirectory"
}
foreach ($requiredDirectory in @($MpvSourceDirectory, $GStreamerSourceDirectory)) {
    if (-not (Test-Path -LiteralPath $requiredDirectory -PathType Container)) {
        throw "Runtime local ausente: $requiredDirectory"
    }
}
if (-not (Test-Path -LiteralPath $GStreamerSourceMsi -PathType Leaf)) {
    throw "MSI oficial local do GStreamer ausente: $GStreamerSourceMsi"
}

$stagingDirectory = Join-Path $releaseRoot ('.medialab-build-' + [guid]::NewGuid().ToString('N'))
New-Item -ItemType Directory -Path $stagingDirectory -Force | Out-Null

try {
    & dotnet publish $project `
        --configuration Release `
        --runtime win-x64 `
        --self-contained true `
        -p:PublishProfile=$profile `
        --output $stagingDirectory
    if ($LASTEXITCODE -ne 0) {
        throw "dotnet publish falhou com codigo $LASTEXITCODE."
    }

    $mpvDestination = Join-Path $stagingDirectory 'runtime\mpv'
    $gstreamerDestination = Join-Path $stagingDirectory 'runtime\gstreamer'
    New-Item -ItemType Directory -Path (Join-Path $mpvDestination 'mpv') -Force | Out-Null
    New-Item -ItemType Directory -Path $gstreamerDestination -Force | Out-Null

    # Only the MPV runtime files used by Semyra are copied. Updaters, shell
    # registration helpers and the upstream installer are intentionally excluded.
    foreach ($relative in @('mpv.exe', 'd3dcompiler_43.dll', 'mpv\fonts.conf')) {
        $source = Join-Path $MpvSourceDirectory $relative
        if (-not (Test-Path -LiteralPath $source -PathType Leaf)) {
            throw "Arquivo MPV obrigatorio ausente: $relative"
        }
        $destination = Join-Path $mpvDestination $relative
        $destinationParent = Split-Path -Parent $destination
        New-Item -ItemType Directory -Path $destinationParent -Force | Out-Null
        Copy-Item -LiteralPath $source -Destination $destination
    }

    # The 15C subset is only a lower bound and has not been proven on a clean VM.
    # The complete, already validated 1.26.11 runtime is used for stability.
    Get-ChildItem -LiteralPath $GStreamerSourceDirectory -Force | ForEach-Object {
        Copy-Item -LiteralPath $_.FullName -Destination $gstreamerDestination -Recurse -Force
    }

    $privatePatterns = @('*.db', '*.sqlite', '*.m3u', '*.m3u8', '.env', '.env.*', '*.log', '*.pem', '*.key', '*.pfx', '*.p12')
    $privateFiles = foreach ($pattern in $privatePatterns) {
        Get-ChildItem -LiteralPath $stagingDirectory -Recurse -Force -File -Filter $pattern -ErrorAction SilentlyContinue
    }
    if (@($privateFiles).Count -gt 0) {
        throw 'A publicacao MediaLab contem arquivo privado ou proibido.'
    }

    function Get-ComponentInventory([string]$Root, [string]$ReleaseRelativeRoot) {
        $items = foreach ($file in Get-ChildItem -LiteralPath $Root -Recurse -File | Sort-Object FullName) {
            $relative = $file.FullName.Substring($Root.TrimEnd('\').Length + 1).Replace('\', '/')
            [ordered]@{
                path = ($ReleaseRelativeRoot.TrimEnd('/') + '/' + $relative)
                bytes = $file.Length
                sha256 = (Get-FileHash -LiteralPath $file.FullName -Algorithm SHA256).Hash
            }
        }
        $canonical = ($items | ForEach-Object { '{0}|{1}|{2}' -f $_.path, $_.bytes, $_.sha256 }) -join "`n"
        $sha = [System.Security.Cryptography.SHA256]::Create()
        try {
            $aggregate = ([BitConverter]::ToString($sha.ComputeHash([Text.Encoding]::UTF8.GetBytes($canonical)))).Replace('-', '')
        }
        finally {
            $sha.Dispose()
        }
        [ordered]@{
            files = @($items)
            fileCount = @($items).Count
            totalBytes = (@($items) | ForEach-Object { [long]$_.bytes } | Measure-Object -Sum).Sum
            contentSetSha256 = $aggregate
        }
    }

    $mpvInventory = Get-ComponentInventory $mpvDestination 'runtime/mpv'
    $gstreamerInventory = Get-ComponentInventory $gstreamerDestination 'runtime/gstreamer'
    $msi = Get-Item -LiteralPath $GStreamerSourceMsi
    $x264Path = Join-Path $gstreamerDestination 'bin\x264-164.dll'
    $gstX264Path = Join-Path $gstreamerDestination 'lib\gstreamer-1.0\gstx264.dll'
    $whipPath = Join-Path $gstreamerDestination 'lib\gstreamer-1.0\gstwebrtchttp.dll'
    $x264Hash = (Get-FileHash -LiteralPath $x264Path -Algorithm SHA256).Hash
    $gstX264Hash = (Get-FileHash -LiteralPath $gstX264Path -Algorithm SHA256).Hash

    $components = @(
        [ordered]@{
            name = 'MPV runtime'
            version = 'v0.41.0-1102-g6c092d978'
            architecture = 'x64'
            path = 'runtime/mpv'
            fileCount = $mpvInventory.fileCount
            totalBytes = $mpvInventory.totalBytes
            contentSetSha256 = $mpvInventory.contentSetSha256
            origin = 'Existing local runtime used by Semyra 14P; exact upstream asset not preserved'
            originProven = $false
            license = 'GPLv2+'
            attributionRequired = $true
            internalUse = 'HOMOLOGACAO INTERNA PERMITIDA'
            redistribution = 'REDISTRIBUICAO AINDA NAO AUTORIZADA'
            classification = 'PROVENIENCIA NAO COMPROVADA'
        },
        [ordered]@{
            name = 'GStreamer runtime'
            version = '1.26.11'
            architecture = 'Windows MSVC x64'
            path = 'runtime/gstreamer'
            packaging = 'complete runtime; preliminary 60.48 MiB subset rejected for this build'
            fileCount = $gstreamerInventory.fileCount
            totalBytes = $gstreamerInventory.totalBytes
            contentSetSha256 = $gstreamerInventory.contentSetSha256
            origin = 'Official GStreamer MSI previously validated locally'
            originProven = $true
            sourceArchive = $msi.Name
            sourceArchiveBytes = $msi.Length
            sourceArchiveSha256 = (Get-FileHash -LiteralPath $msi.FullName -Algorithm SHA256).Hash
            license = 'Core LGPL; bundled plugins and third-party libraries have additional licenses'
            attributionRequired = $true
            internalUse = 'HOMOLOGACAO INTERNA PERMITIDA'
            redistribution = 'REDISTRIBUICAO AINDA NAO AUTORIZADA'
            classification = 'REDISTRIBUICAO AVALIADA, CONDICIONADA A CONFORMIDADE'
        },
        [ordered]@{
            name = 'x264 runtime and GStreamer plugin'
            version = 'x264-164 / GStreamer 1.26.11'
            architecture = 'x64'
            path = 'runtime/gstreamer/bin/x264-164.dll; runtime/gstreamer/lib/gstreamer-1.0/gstx264.dll'
            totalBytes = (Get-Item -LiteralPath $x264Path).Length + (Get-Item -LiteralPath $gstX264Path).Length
            sha256 = @($x264Hash, $gstX264Hash)
            origin = 'Bundled by the official GStreamer runtime'
            originProven = $true
            license = 'GPL/commercial licensing review pending'
            attributionRequired = $true
            internalUse = 'HOMOLOGACAO INTERNA PERMITIDA'
            redistribution = 'REDISTRIBUICAO AINDA NAO AUTORIZADA'
            classification = 'REDISTRIBUICAO AINDA NAO AUTORIZADA'
        },
        [ordered]@{
            name = 'GStreamer WebRTC HTTP/WHIP plugin'
            version = 'bundled with GStreamer 1.26.11'
            architecture = 'x64'
            path = 'runtime/gstreamer/lib/gstreamer-1.0/gstwebrtchttp.dll'
            totalBytes = (Get-Item -LiteralPath $whipPath).Length
            sha256 = (Get-FileHash -LiteralPath $whipPath -Algorithm SHA256).Hash
            origin = 'Bundled by the official GStreamer runtime'
            originProven = $true
            license = 'MPL-2.0 as reported by the local plugin metadata'
            attributionRequired = $true
            internalUse = 'HOMOLOGACAO INTERNA PERMITIDA'
            redistribution = 'REDISTRIBUICAO AVALIADA, CONDICIONADA A CONFORMIDADE'
            classification = 'REDISTRIBUICAO AVALIADA, CONDICIONADA A CONFORMIDADE'
        }
    )

    $dependencyManifest = [ordered]@{
        schemaVersion = 1
        product = 'Semyra MediaLab Preview'
        version = '0.15.0-preview.1'
        architecture = 'win-x64'
        generatedUtc = [DateTime]::UtcNow.ToString('o')
        publicDistributionAllowed = $false
        components = $components
        files = @($mpvInventory.files) + @($gstreamerInventory.files)
    }
    $dependencyManifest | ConvertTo-Json -Depth 8 | Set-Content -LiteralPath (Join-Path $stagingDirectory 'semyra-medialab-dependencies.json') -Encoding UTF8

    @'
SEMYRA MEDIALAB PREVIEW - THIRD-PARTY NOTICE (INTERNAL HOMOLOGATION ONLY)

This private build includes an MPV GPLv2+ runtime whose exact upstream asset and
corresponding source bundle have not yet been proven. It also includes the official
GStreamer 1.26.11 Windows MSVC x64 runtime. GStreamer components use multiple
licenses; the active stable pipelines include gstx264/x264 (GPL/commercial review
pending), gst-libav/FFmpeg components and the MPL-2.0 WebRTC HTTP/WHIP plugin.

H.264 patent obligations, x264 licensing, complete source/notice obligations and
Authenticode/SmartScreen strategy remain open. Technical operation of this package
does not authorize public redistribution. Do not publish or distribute it.
'@ | Set-Content -LiteralPath (Join-Path $stagingDirectory 'THIRD-PARTY-NOTICES-MEDIALAB.txt') -Encoding UTF8

    $files = @(Get-ChildItem -LiteralPath $stagingDirectory -Recurse -File)
    [ordered]@{
        product = 'Semyra MediaLab Preview'
        version = '0.15.0-preview.1'
        runtimeIdentifier = 'win-x64'
        selfContained = $true
        singleFile = $false
        trimmed = $false
        readyToRun = $false
        officialOrigin = 'https://semyra.jzlcode.com.br/'
        nativeMediaRuntimesIncluded = $true
        gstreamerPackaging = 'full'
        publicDistributionAllowed = $false
        fileCountBeforeManifest = $files.Count
        totalBytesBeforeManifest = ($files | Measure-Object -Property Length -Sum).Sum
    } | ConvertTo-Json | Set-Content -LiteralPath (Join-Path $stagingDirectory 'semyra-release.json') -Encoding UTF8

    & (Join-Path $PSScriptRoot 'Test-WindowsMediaLabRelease.ps1') -PublishDirectory $stagingDirectory

    Move-Item -LiteralPath $stagingDirectory -Destination $OutputDirectory
    Write-Host "Release MediaLab produzido em: $OutputDirectory"
}
catch {
    Write-Error $_
    Write-Host "Staging preservado para diagnostico: $stagingDirectory"
    exit 1
}
