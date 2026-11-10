[CmdletBinding()]
param(
    [string]$ReleaseDirectory,
    [string]$InstallerScript,
    [switch]$AsJson
)

$ErrorActionPreference = 'Stop'
$repositoryRoot = [System.IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..\..\..'))

if ([string]::IsNullOrWhiteSpace($ReleaseDirectory)) {
    $ReleaseDirectory = Join-Path $repositoryRoot 'storage\cache\releases\semyra-desktop-0.15.0-preview.1-win-x64'
}
if ([string]::IsNullOrWhiteSpace($InstallerScript)) {
    $InstallerScript = Join-Path $repositoryRoot 'desktop\installer\Semyra.Preview.iss'
}

$ReleaseDirectory = [System.IO.Path]::GetFullPath($ReleaseDirectory)
$InstallerScript = [System.IO.Path]::GetFullPath($InstallerScript)

if (-not (Test-Path -LiteralPath $ReleaseDirectory -PathType Container)) {
    throw "Release 15B ausente: $ReleaseDirectory"
}
if (-not (Test-Path -LiteralPath $InstallerScript -PathType Leaf)) {
    throw "Script Inno Setup ausente: $InstallerScript"
}

$manifestPath = Join-Path $ReleaseDirectory 'semyra-release.json'
if (-not (Test-Path -LiteralPath $manifestPath -PathType Leaf)) {
    throw 'Manifesto semyra-release.json ausente.'
}
$manifest = Get-Content -Raw -LiteralPath $manifestPath -Encoding UTF8 | ConvertFrom-Json

if ($manifest.product -ne 'Semyra Desktop' `
    -or $manifest.version -ne '0.15.0-preview.1' `
    -or $manifest.runtimeIdentifier -ne 'win-x64' `
    -or -not $manifest.selfContained `
    -or $manifest.singleFile `
    -or $manifest.nativeMediaRuntimesIncluded) {
    throw 'O manifesto do Release nao corresponde ao artefato 15B aprovado.'
}

$required = @(
    'Semyra.Desktop.exe',
    'Semyra.Desktop.dll',
    'Semyra.Desktop.deps.json',
    'Semyra.Desktop.runtimeconfig.json',
    'Semyra.MediaHost.exe',
    'Semyra.MediaHost.dll',
    'Semyra.MediaHost.deps.json',
    'Semyra.MediaHost.runtimeconfig.json',
    'Microsoft.Web.WebView2.Core.dll',
    'Microsoft.Web.WebView2.Wpf.dll',
    'WebView2Loader.dll',
    'Microsoft.Data.Sqlite.dll',
    'e_sqlite3.dll',
    'WinRT.Runtime.dll',
    'WinRT.Host.Shim.dll'
)
foreach ($name in $required) {
    if (-not (Test-Path -LiteralPath (Join-Path $ReleaseDirectory $name) -PathType Leaf)) {
        throw "Arquivo obrigatorio ausente do Release: $name"
    }
}

$allFiles = @(Get-ChildItem -LiteralPath $ReleaseDirectory -Recurse -File -Force)
if ($allFiles.Count -ne ([int]$manifest.fileCountBeforeManifest + 1)) {
    throw "Contagem do Release divergiu do manifesto: $($allFiles.Count) arquivos."
}

$included = [System.Collections.Generic.Dictionary[string, System.IO.FileInfo]]::new(
    [System.StringComparer]::OrdinalIgnoreCase)

function Add-IncludedFile([System.IO.FileInfo]$File) {
    $prefix = $ReleaseDirectory.TrimEnd([System.IO.Path]::DirectorySeparatorChar) + [System.IO.Path]::DirectorySeparatorChar
    if (-not $File.FullName.StartsWith($prefix, [System.StringComparison]::OrdinalIgnoreCase)) {
        throw "Arquivo fora do Release: $($File.FullName)"
    }
    $relative = $File.FullName.Substring($prefix.Length)
    $included[$relative] = $File
}

Get-ChildItem -LiteralPath $ReleaseDirectory -File |
    Where-Object { $_.Extension -in '.exe', '.dll', '.json' } |
    ForEach-Object { Add-IncludedFile $_ }

Get-ChildItem -LiteralPath $ReleaseDirectory -Recurse -File -Filter '*.resources.dll' |
    ForEach-Object { Add-IncludedFile $_ }

$nativeDirectory = Join-Path $ReleaseDirectory 'runtimes\win-x64\native'
if (-not (Test-Path -LiteralPath $nativeDirectory -PathType Container)) {
    throw 'Diretorio nativo win-x64 ausente do Release.'
}
Get-ChildItem -LiteralPath $nativeDirectory -File -Filter '*.dll' |
    ForEach-Object { Add-IncludedFile $_ }

$knownExcluded = @(
    'Microsoft.Web.WebView2.Core.xml',
    'Microsoft.Web.WebView2.WinForms.xml',
    'Microsoft.Web.WebView2.Wpf.xml',
    'Semyra.MediaHost.pdb'
)
$unexpectedExcluded = @()
foreach ($file in $allFiles) {
    $prefix = $ReleaseDirectory.TrimEnd([System.IO.Path]::DirectorySeparatorChar) + [System.IO.Path]::DirectorySeparatorChar
    $relative = $file.FullName.Substring($prefix.Length)
    if (-not $included.ContainsKey($relative) -and $relative -notin $knownExcluded) {
        $unexpectedExcluded += $relative
    }
}
if ($unexpectedExcluded.Count -gt 0) {
    throw "Arquivos sem classificacao no Release: $($unexpectedExcluded -join ', ')"
}

$forbiddenNamePatterns = @(
    '(?i)(^|[\\/])runtime[\\/](mpv|gstreamer)([\\/]|$)',
    '(?i)(^|[\\/])x264[^\\/]*\.(dll|exe)$',
    '(?i)(^|[\\/])\.env(\..*)?$',
    '(?i)\.(db|sqlite|m3u|m3u8|log|pem|key|pfx|p12)$',
    '(?i)(^|[\\/])(cache|logs?|webview2-profile)([\\/]|$)'
)
$forbiddenIncluded = foreach ($relative in $included.Keys) {
    foreach ($pattern in $forbiddenNamePatterns) {
        if ($relative -match $pattern) { $relative; break }
    }
}
if (@($forbiddenIncluded).Count -gt 0) {
    throw "Arquivo proibido seria empacotado: $(@($forbiddenIncluded) -join ', ')"
}

$scriptText = Get-Content -Raw -LiteralPath $InstallerScript -Encoding UTF8
$requiredScriptFragments = @(
    '#define SemyraAppId "{{02229D4E-6DD5-4215-A5E6-1EDE683A47D8}"',
    'AppId={#SemyraAppId}',
    'PrivilegesRequired=lowest',
    'DefaultDirName={localappdata}\Programs\Semyra-Preview',
    'ArchitecturesAllowed=x64compatible',
    'CloseApplications=yes',
    'RestartApplications=no',
    'ChangesEnvironment=no',
    'ChangesAssociations=no',
    'CreateUninstallRegKey=yes',
    'WebView2ClientKey',
    'HOMOLOGACAO INTERNA'
)
foreach ($fragment in $requiredScriptFragments) {
    if (-not $scriptText.Contains($fragment)) {
        throw "Diretiva obrigatoria ausente do instalador: $fragment"
    }
}

$forbiddenScriptFragments = @(
    '[Registry]',
    '[UninstallDelete]',
    'PrivilegesRequired=admin',
    'runhidden',
    'restartreplace',
    'sharedfile',
    'regserver',
    'runtime\mpv',
    'runtime\gstreamer'
)
foreach ($fragment in $forbiddenScriptFragments) {
    if ($scriptText.Contains($fragment)) {
        throw "Comportamento proibido no instalador: $fragment"
    }
}

$includedFiles = @($included.Values)
$result = [ordered]@{
    releaseDirectory = $ReleaseDirectory
    installerScript = $InstallerScript
    releaseVersion = $manifest.version
    releaseFileCount = $allFiles.Count
    includedFileCount = $includedFiles.Count
    includedBytes = ($includedFiles | Measure-Object Length -Sum).Sum
    intentionallyExcluded = $knownExcluded
    appId = '{02229D4E-6DD5-4215-A5E6-1EDE683A47D8}'
    installMode = 'per-user'
    installDirectory = '%LOCALAPPDATA%\Programs\Semyra-Preview'
    nativeMediaRuntimesIncluded = $false
    validation = 'passed'
}

if ($AsJson) {
    $result | ConvertTo-Json -Depth 4
}
else {
    $result.GetEnumerator() | ForEach-Object {
        $value = if ($_.Value -is [array]) { $_.Value -join ', ' } else { $_.Value }
        '{0}: {1}' -f $_.Key, $value
    }
}
