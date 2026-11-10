[CmdletBinding()]
param(
    [string]$ReleaseDirectory,
    [string]$OutputDirectory,
    [string]$CompilerPath
)

$ErrorActionPreference = 'Stop'
$repositoryRoot = [System.IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..\..\..'))
$installerScript = Join-Path $repositoryRoot 'desktop\installer\Semyra.Preview.iss'
$validator = Join-Path $PSScriptRoot 'Test-InstallerInputs.ps1'

if ([string]::IsNullOrWhiteSpace($ReleaseDirectory)) {
    $ReleaseDirectory = Join-Path $repositoryRoot 'storage\cache\releases\semyra-desktop-0.15.0-preview.1-win-x64'
}
if ([string]::IsNullOrWhiteSpace($OutputDirectory)) {
    $OutputDirectory = Join-Path $repositoryRoot 'storage\cache\releases'
}

$ReleaseDirectory = [System.IO.Path]::GetFullPath($ReleaseDirectory)
$OutputDirectory = [System.IO.Path]::GetFullPath($OutputDirectory)
$releaseRoot = [System.IO.Path]::GetFullPath((Join-Path $repositoryRoot 'storage\cache\releases'))

if (-not $OutputDirectory.Equals($releaseRoot, [System.StringComparison]::OrdinalIgnoreCase)) {
    throw 'O instalador de homologacao deve ser gravado diretamente em storage\cache\releases.'
}

& $validator -ReleaseDirectory $ReleaseDirectory -InstallerScript $installerScript

if ([string]::IsNullOrWhiteSpace($CompilerPath)) {
    $command = Get-Command ISCC.exe -ErrorAction SilentlyContinue
    if ($null -ne $command) {
        $CompilerPath = $command.Source
    }
}

if ([string]::IsNullOrWhiteSpace($CompilerPath)) {
    $candidates = @(
        (Join-Path $env:ProgramFiles 'Inno Setup 7\ISCC.exe'),
        (Join-Path ${env:ProgramFiles(x86)} 'Inno Setup 7\ISCC.exe'),
        (Join-Path $env:ProgramFiles 'Inno Setup 6\ISCC.exe'),
        (Join-Path ${env:ProgramFiles(x86)} 'Inno Setup 6\ISCC.exe'),
        (Join-Path $env:LOCALAPPDATA 'Programs\Inno Setup 7\ISCC.exe'),
        (Join-Path $env:LOCALAPPDATA 'Programs\Inno Setup 6\ISCC.exe')
    )
    $CompilerPath = $candidates |
        Where-Object { -not [string]::IsNullOrWhiteSpace($_) -and (Test-Path -LiteralPath $_ -PathType Leaf) } |
        Select-Object -First 1
}

if ([string]::IsNullOrWhiteSpace($CompilerPath) -or -not (Test-Path -LiteralPath $CompilerPath -PathType Leaf)) {
    throw 'ISCC.exe nao encontrado. Instale manualmente Inno Setup 6.3 ou superior a partir de https://jrsoftware.org/isdl.php e execute este script novamente. Nenhum download foi realizado.'
}

$compiler = Get-Item -LiteralPath $CompilerPath
$productVersion = $compiler.VersionInfo.ProductVersion
$version = $null
$versionIsCompatible = [version]::TryParse(($productVersion -replace '[^0-9.].*$', ''), [ref]$version) -and $version -ge [version]'6.3'

if (-not $versionIsCompatible) {
    $compilerBanner = (& $CompilerPath '/?' 2>&1 | Select-Object -First 1) -as [string]
    if ($compilerBanner -match '^Inno Setup (?<major>[0-9]+) Command-Line Compiler$') {
        $versionIsCompatible = [int]$Matches['major'] -ge 7
    }
}

if (-not $versionIsCompatible) {
    throw "Inno Setup 6.3 ou superior e obrigatorio. Encontrado: $productVersion"
}

$outputName = 'Semyra-0.15.0-preview.1-win-x64-setup.exe'
$outputPath = Join-Path $OutputDirectory $outputName
if (Test-Path -LiteralPath $outputPath) {
    throw "O instalador ja existe e nao sera sobrescrito automaticamente: $outputPath"
}

$arguments = @(
    "/DReleaseDir=$ReleaseDirectory",
    "/DInstallerOutputDir=$OutputDirectory",
    '/DSemyraVersion=0.15.0-preview.1',
    $installerScript
)

& $CompilerPath @arguments
if ($LASTEXITCODE -ne 0) {
    throw "A compilacao Inno Setup falhou com codigo $LASTEXITCODE."
}
if (-not (Test-Path -LiteralPath $outputPath -PathType Leaf)) {
    throw "O compilador concluiu sem produzir o artefato esperado: $outputPath"
}

$output = Get-Item -LiteralPath $outputPath
$hash = Get-FileHash -LiteralPath $outputPath -Algorithm SHA256
$signature = Get-AuthenticodeSignature -LiteralPath $outputPath

[ordered]@{
    compilerPath = $CompilerPath
    compilerVersion = $productVersion
    installerPath = $outputPath
    installerBytes = $output.Length
    sha256 = $hash.Hash
    signatureStatus = $signature.Status.ToString()
    publicDistributionAllowed = $false
} | ConvertTo-Json
