[CmdletBinding()]
param()

$ErrorActionPreference = 'Stop'

$manualRoot = $PSScriptRoot
$temporaryRoot = Join-Path ([IO.Path]::GetTempPath()) ('semyra-browser-deps-' + [Guid]::NewGuid().ToString('N'))

function Install-PinnedBundle {
    param(
        [Parameter(Mandatory)] [string] $Package,
        [Parameter(Mandatory)] [string] $Source,
        [Parameter(Mandatory)] [string] $Destination,
        [Parameter(Mandatory)] [string] $LicenseSource,
        [Parameter(Mandatory)] [string] $LicenseDestination,
        [Parameter(Mandatory)] [string] $ExpectedSha256
    )

    $packageRoot = Join-Path $temporaryRoot ([Guid]::NewGuid().ToString('N'))
    New-Item -ItemType Directory -Path $packageRoot | Out-Null
    $archiveName = (& npm pack $Package --silent --pack-destination $packageRoot | Select-Object -Last 1).Trim()
    if ($LASTEXITCODE -ne 0 -or $archiveName -eq '') {
        throw "Could not download pinned package $Package."
    }

    $archivePath = Join-Path $packageRoot $archiveName
    & tar -xf $archivePath -C $packageRoot
    if ($LASTEXITCODE -ne 0) {
        throw "Could not extract pinned package $Package."
    }

    $bundlePath = Join-Path (Join-Path $packageRoot 'package') $Source
    $licensePath = Join-Path (Join-Path $packageRoot 'package') $LicenseSource
    $actualHash = (Get-FileHash -LiteralPath $bundlePath -Algorithm SHA256).Hash
    if ($actualHash -ne $ExpectedSha256) {
        throw "SHA-256 mismatch for $Package."
    }

    $destinationPath = Join-Path $manualRoot $Destination
    $licenseDestinationPath = Join-Path $manualRoot $LicenseDestination
    New-Item -ItemType Directory -Path (Split-Path $destinationPath) -Force | Out-Null
    New-Item -ItemType Directory -Path (Split-Path $licenseDestinationPath) -Force | Out-Null
    Copy-Item -LiteralPath $bundlePath -Destination $destinationPath -Force
    Copy-Item -LiteralPath $licensePath -Destination $licenseDestinationPath -Force
    Write-Output "Prepared $Package ($actualHash)"
}

try {
    New-Item -ItemType Directory -Path $temporaryRoot | Out-Null

    Install-PinnedBundle `
        -Package 'hls.js@1.7.3' `
        -Source 'dist/hls.min.js' `
        -Destination 'iptv-spike/vendor/hls.min.js' `
        -LicenseSource 'LICENSE' `
        -LicenseDestination 'iptv-spike/vendor/LICENSE-hls.js.txt' `
        -ExpectedSha256 'A12E7EE1CD64A69DCDB314157E45DAFCBA705BFB0B1440B7935CB265D374423E'

    Install-PinnedBundle `
        -Package 'mpegts.js@1.8.2' `
        -Source 'dist/mpegts.js' `
        -Destination 'iptv-spike/vendor/mpegts.js' `
        -LicenseSource 'LICENSE' `
        -LicenseDestination 'iptv-spike/vendor/LICENSE-mpegts.js.txt' `
        -ExpectedSha256 'BDA31748736A69CB610C2EDF4623E633F1F4F47B5BDA83668C8D287E51B0C3A8'

    Install-PinnedBundle `
        -Package 'livekit-client@2.22.3' `
        -Source 'dist/livekit-client.umd.js' `
        -Destination 'livekit-spike/vendor-js/livekit-client.umd.js' `
        -LicenseSource 'LICENSE' `
        -LicenseDestination 'livekit-spike/vendor-js/LICENSE-livekit-client.txt' `
        -ExpectedSha256 '7FA17E37AF5E996D8A25F15A637DCC0620215BC01B394E5D209F726AFE7DC04D'

    $projectRoot = Resolve-Path (Join-Path $manualRoot '..\..')
    $productionRoot = Join-Path $projectRoot 'public\assets\vendor\livekit'
    New-Item -ItemType Directory -Path $productionRoot -Force | Out-Null
    $preparedBundle = Join-Path $manualRoot 'livekit-spike\vendor-js\livekit-client.umd.js'
    $preparedLicense = Join-Path $manualRoot 'livekit-spike\vendor-js\LICENSE-livekit-client.txt'
    if ((Get-FileHash -LiteralPath $preparedBundle -Algorithm SHA256).Hash `
        -ne '7FA17E37AF5E996D8A25F15A637DCC0620215BC01B394E5D209F726AFE7DC04D') {
        throw 'Prepared livekit-client bundle failed the production integrity check.'
    }
    Copy-Item -LiteralPath $preparedBundle -Destination (Join-Path $productionRoot 'livekit-client.umd.js') -Force
    Copy-Item -LiteralPath $preparedLicense -Destination (Join-Path $productionRoot 'LICENSE-livekit-client.txt') -Force
} finally {
    if (Test-Path -LiteralPath $temporaryRoot) {
        Remove-Item -LiteralPath $temporaryRoot -Recurse -Force
    }
}
