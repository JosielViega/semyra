[CmdletBinding()]
param([string]$ReleaseDirectory, [string]$OutputDirectory, [string]$CompilerPath)

$ErrorActionPreference = 'Stop'
$repositoryRoot = [System.IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..\..\..'))
$installerScript = Join-Path $repositoryRoot 'desktop\installer\Semyra.MediaLab.Preview.iss'
$validator = Join-Path $PSScriptRoot 'Test-MediaLabInstallerInputs.ps1'
if ([string]::IsNullOrWhiteSpace($ReleaseDirectory)) { $ReleaseDirectory = Join-Path $repositoryRoot 'storage\cache\releases\semyra-desktop-0.15.0-preview.1-MediaLab-win-x64' }
if ([string]::IsNullOrWhiteSpace($OutputDirectory)) { $OutputDirectory = Join-Path $repositoryRoot 'storage\cache\releases' }
$ReleaseDirectory = [System.IO.Path]::GetFullPath($ReleaseDirectory)
$OutputDirectory = [System.IO.Path]::GetFullPath($OutputDirectory)
$releaseRoot = [System.IO.Path]::GetFullPath((Join-Path $repositoryRoot 'storage\cache\releases'))
if (-not $OutputDirectory.Equals($releaseRoot, [System.StringComparison]::OrdinalIgnoreCase)) { throw 'O instalador MediaLab deve ser gravado diretamente em storage\cache\releases.' }

& $validator -ReleaseDirectory $ReleaseDirectory -InstallerScript $installerScript | Out-Null
if ([string]::IsNullOrWhiteSpace($CompilerPath)) {
    $candidates = @((Join-Path $env:ProgramFiles 'Inno Setup 7\ISCC.exe'), (Join-Path ${env:ProgramFiles(x86)} 'Inno Setup 7\ISCC.exe'), (Join-Path $env:LOCALAPPDATA 'Programs\Inno Setup 7\ISCC.exe'))
    $CompilerPath = $candidates | Where-Object { Test-Path -LiteralPath $_ -PathType Leaf } | Select-Object -First 1
}
if ([string]::IsNullOrWhiteSpace($CompilerPath) -or -not (Test-Path -LiteralPath $CompilerPath -PathType Leaf)) { throw 'Inno Setup 7 nao encontrado. Nenhum download foi realizado.' }
$compilerBanner = (& $CompilerPath '/?' 2>&1 | Select-Object -First 1) -as [string]
if ($compilerBanner -notmatch '^Inno Setup 7 Command-Line Compiler$') { throw "Inno Setup 7 e obrigatorio. Encontrado: $compilerBanner" }

$outputPath = Join-Path $OutputDirectory 'Semyra-0.15.0-preview.1-MediaLab-win-x64-setup.exe'
if (Test-Path -LiteralPath $outputPath) { throw "O instalador MediaLab ja existe e nao sera sobrescrito: $outputPath" }
& $CompilerPath "/DReleaseDir=$ReleaseDirectory" "/DInstallerOutputDir=$OutputDirectory" '/DSemyraVersion=0.15.0-preview.1' $installerScript
if ($LASTEXITCODE -ne 0) { throw "Compilacao Inno falhou com codigo $LASTEXITCODE." }
if (-not (Test-Path -LiteralPath $outputPath -PathType Leaf)) { throw 'Instalador MediaLab esperado nao foi produzido.' }
$output = Get-Item -LiteralPath $outputPath
$signature = Get-AuthenticodeSignature -LiteralPath $outputPath
[ordered]@{
    compilerPath = $CompilerPath; compilerVersion = '7.1.1'; installerPath = $outputPath
    installerBytes = $output.Length; sha256 = (Get-FileHash -LiteralPath $outputPath -Algorithm SHA256).Hash
    signatureStatus = $signature.Status.ToString(); publicDistributionAllowed = $false
} | ConvertTo-Json
