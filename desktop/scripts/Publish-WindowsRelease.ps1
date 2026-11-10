[CmdletBinding()]
param(
    [string]$OutputDirectory
)

$ErrorActionPreference = 'Stop'
$repositoryRoot = [System.IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..\..'))
$project = Join-Path $repositoryRoot 'desktop\src\Semyra.Desktop\Semyra.Desktop.csproj'
$profile = 'WindowsX64'

if ([string]::IsNullOrWhiteSpace($OutputDirectory)) {
    $OutputDirectory = Join-Path $repositoryRoot 'storage\cache\releases\semyra-desktop-0.15.0-preview.1-win-x64'
}
$OutputDirectory = [System.IO.Path]::GetFullPath($OutputDirectory)
$releaseRoot = [System.IO.Path]::GetFullPath((Join-Path $repositoryRoot 'storage\cache\releases'))

if (-not $OutputDirectory.StartsWith($releaseRoot + [System.IO.Path]::DirectorySeparatorChar, [System.StringComparison]::OrdinalIgnoreCase)) {
    throw 'A saída oficial deve ficar dentro de storage\cache\releases.'
}
if (Test-Path -LiteralPath $OutputDirectory) {
    $existing = @(Get-ChildItem -LiteralPath $OutputDirectory -Force -ErrorAction SilentlyContinue)
    if ($existing.Count -gt 0) {
        throw "A saída já contém arquivos: $OutputDirectory. Use um diretório vazio para impedir mistura de builds."
    }
}
else {
    New-Item -ItemType Directory -Path $OutputDirectory -Force | Out-Null
}

& dotnet publish $project `
    --configuration Release `
    --runtime win-x64 `
    --self-contained true `
    -p:PublishProfile=$profile `
    --output $OutputDirectory
if ($LASTEXITCODE -ne 0) {
    throw "dotnet publish falhou com código $LASTEXITCODE."
}

$required = @(
    'Semyra.Desktop.exe',
    'Semyra.Desktop.dll',
    'Semyra.Desktop.deps.json',
    'Semyra.Desktop.runtimeconfig.json',
    'Semyra.MediaHost.exe',
    'Semyra.MediaHost.dll',
    'Semyra.MediaHost.runtimeconfig.json',
    'WinRT.Runtime.dll',
    'WinRT.Host.Shim.dll'
)
foreach ($name in $required) {
    if (-not (Test-Path -LiteralPath (Join-Path $OutputDirectory $name) -PathType Leaf)) {
        throw "Arquivo obrigatório ausente do publish: $name"
    }
}

$desktopDepsPath = Join-Path $OutputDirectory 'Semyra.Desktop.deps.json'
$desktopDeps = Get-Content -Raw -LiteralPath $desktopDepsPath
if ($desktopDeps -notmatch 'Microsoft\.Windows\.CsWinRT/1\.6\.5' `
    -or $desktopDeps -notmatch 'lib/net5\.0/WinRT\.Runtime\.dll' `
    -or $desktopDeps -notmatch 'lib/net5\.0/WinRT\.Host\.Shim\.dll') {
    throw 'O grafo de dependências do publish não contém o runtime CsWinRT exigido pelo WebView2CompositionControl.'
}

$privatePatterns = @('*.db', '*.sqlite', '*.m3u', '*.m3u8', '.env', '.env.*', '*.log', '*.pem', '*.key', '*.pfx', '*.p12')
$privateFiles = foreach ($pattern in $privatePatterns) {
    Get-ChildItem -LiteralPath $OutputDirectory -Recurse -Force -File -Filter $pattern -ErrorAction SilentlyContinue
}
if (@($privateFiles).Count -gt 0) {
    throw 'A publicação contém arquivo privado ou proibido.'
}

if (Test-Path -LiteralPath (Join-Path $OutputDirectory 'runtime\mpv')) {
    throw 'MPV não pode integrar o artefato público da Etapa 15B.'
}
if (Test-Path -LiteralPath (Join-Path $OutputDirectory 'runtime\gstreamer')) {
    throw 'GStreamer não pode integrar o artefato público da Etapa 15B.'
}

$files = @(Get-ChildItem -LiteralPath $OutputDirectory -Recurse -File)
$manifest = [ordered]@{
    product = 'Semyra Desktop'
    version = '0.15.0-preview.1'
    runtimeIdentifier = 'win-x64'
    selfContained = $true
    singleFile = $false
    trimmed = $false
    readyToRun = $false
    officialOrigin = 'https://semyra.jzlcode.com.br/'
    nativeMediaRuntimesIncluded = $false
    fileCountBeforeManifest = $files.Count
    totalBytesBeforeManifest = ($files | Measure-Object -Property Length -Sum).Sum
}
$manifest | ConvertTo-Json | Set-Content -LiteralPath (Join-Path $OutputDirectory 'semyra-release.json') -Encoding UTF8

& (Join-Path $PSScriptRoot 'Test-WindowsRelease.ps1') -PublishDirectory $OutputDirectory
if ($LASTEXITCODE -ne 0) {
    throw "A validação do Release falhou com código $LASTEXITCODE."
}

Write-Host "Release produzido em: $OutputDirectory"
