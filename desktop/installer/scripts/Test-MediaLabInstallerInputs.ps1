[CmdletBinding()]
param([string]$ReleaseDirectory, [string]$InstallerScript, [switch]$AsJson)

$ErrorActionPreference = 'Stop'
$repositoryRoot = [System.IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..\..\..'))
if ([string]::IsNullOrWhiteSpace($ReleaseDirectory)) { $ReleaseDirectory = Join-Path $repositoryRoot 'storage\cache\releases\semyra-desktop-0.15.0-preview.1-MediaLab-win-x64' }
if ([string]::IsNullOrWhiteSpace($InstallerScript)) { $InstallerScript = Join-Path $repositoryRoot 'desktop\installer\Semyra.MediaLab.Preview.iss' }
$ReleaseDirectory = [System.IO.Path]::GetFullPath($ReleaseDirectory)
$InstallerScript = [System.IO.Path]::GetFullPath($InstallerScript)
if (-not (Test-Path -LiteralPath $ReleaseDirectory -PathType Container)) { throw "Release MediaLab ausente: $ReleaseDirectory" }
if (-not (Test-Path -LiteralPath $InstallerScript -PathType Leaf)) { throw "Script MediaLab ausente: $InstallerScript" }

& (Join-Path $repositoryRoot 'desktop\scripts\Test-WindowsMediaLabRelease.ps1') -PublishDirectory $ReleaseDirectory | Out-Null

$scriptText = Get-Content -Raw -LiteralPath $InstallerScript
$requiredFragments = @(
    '#define SemyraAppId "{{A6D30BE1-4E28-4A62-8D28-0DF4F8E18331}"',
    'AppName=Semyra MediaLab Preview',
    'DefaultDirName={localappdata}\Programs\Semyra-MediaLab-Preview',
    'PrivilegesRequired=lowest', 'ArchitecturesAllowed=x64compatible',
    'ChangesEnvironment=no', 'runtime\mpv\*', 'runtime\gstreamer\*', 'HOMOLOGACAO INTERNA'
)
foreach ($fragment in $requiredFragments) { if (-not $scriptText.Contains($fragment)) { throw "Diretiva MediaLab ausente: $fragment" } }
$forbiddenFragments = @(
    '{{02229D4E-6DD5-4215-A5E6-1EDE683A47D8}',
    'DefaultDirName={localappdata}\Programs\Semyra-Preview',
    '[Registry]', '[UninstallDelete]', 'PrivilegesRequired=admin',
    'netsh', 'firewall', 'setx', 'ChangesEnvironment=yes',
    'runhidden', 'restartreplace', 'sharedfile', 'regserver'
)
foreach ($fragment in $forbiddenFragments) { if ($scriptText.Contains($fragment)) { throw "Comportamento proibido no MediaLab: $fragment" } }

$privatePatterns = @('*.db', '*.sqlite', '*.m3u', '*.m3u8', '.env', '.env.*', '*.log', '*.pem', '*.key', '*.pfx', '*.p12')
$privateFiles = foreach ($pattern in $privatePatterns) { Get-ChildItem -LiteralPath $ReleaseDirectory -Recurse -Force -File -Filter $pattern -ErrorAction SilentlyContinue }
if (@($privateFiles).Count -gt 0) { throw 'Release MediaLab contem dados privados.' }
$files = @(Get-ChildItem -LiteralPath $ReleaseDirectory -Recurse -File)
$result = [ordered]@{
    releaseDirectory = $ReleaseDirectory; installerScript = $InstallerScript
    appId = '{A6D30BE1-4E28-4A62-8D28-0DF4F8E18331}'; installMode = 'per-user'
    installDirectory = '%LOCALAPPDATA%\Programs\Semyra-MediaLab-Preview'
    releaseFileCount = $files.Count; releaseBytes = ($files | Measure-Object Length -Sum).Sum
    nativeMediaRuntimesIncluded = $true; globalEnvironmentChanges = $false
    firewallChanges = $false; publicDistributionAllowed = $false; validation = 'passed'
}
if ($AsJson) { $result | ConvertTo-Json -Depth 4 } else { $result.GetEnumerator() | ForEach-Object { '{0}: {1}' -f $_.Key, $_.Value } }
