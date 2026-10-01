[CmdletBinding()]
param()

$ErrorActionPreference = 'Stop'
$ProgressPreference = 'SilentlyContinue'

$script:requestId = 0
$script:fileLoadedSeen = $false
$script:reader = $null
$script:writer = $null
$script:mpvProcess = $null
$script:failureStage = 'initialization'

function Find-YnoTvMpv {
    $roots = @(
        $env:LOCALAPPDATA,
        $env:APPDATA,
        $env:ProgramFiles,
        ${env:ProgramFiles(x86)}
    ) | Where-Object { -not [string]::IsNullOrWhiteSpace($_) -and (Test-Path -LiteralPath $_) }

    foreach ($root in $roots) {
        $candidateDirectories = @(
            (Join-Path $root 'ynoTV'),
            (Join-Path $root 'YnoTV'),
            (Join-Path (Join-Path $root 'Programs') 'ynoTV'),
            (Join-Path (Join-Path $root 'Programs') 'YnoTV')
        ) | Select-Object -Unique

        foreach ($directory in $candidateDirectories) {
            if (-not (Test-Path -LiteralPath $directory -PathType Container)) {
                continue
            }

            $mpv = Get-ChildItem -LiteralPath $directory -Recurse -File -Filter 'mpv.exe' -ErrorAction SilentlyContinue |
                Select-Object -First 1
            if ($null -ne $mpv) {
                return $mpv.FullName
            }
        }
    }

    return $null
}

function Read-IpcObject {
    param([int]$TimeoutMs)

    # Keep only one read in flight. Abandoned ReadLineAsync tasks retain the
    # named pipe and can keep Windows PowerShell alive after mpv has exited.
    $line = $script:reader.ReadLine()
    if ($null -eq $line) {
        throw 'IPC connection closed.'
    }

    $message = $line | ConvertFrom-Json
    if ($message.event -eq 'file-loaded') {
        $script:fileLoadedSeen = $true
    }

    return $message
}

function Send-IpcCommand {
    param(
        [Parameter(Mandatory = $true)][object[]]$Command,
        [int]$TimeoutMs = 5000
    )

    $script:requestId++
    $id = $script:requestId
    $payload = [ordered]@{
        command = $Command
        request_id = $id
    } | ConvertTo-Json -Compress -Depth 6

    $script:writer.WriteLine($payload)
    $deadline = [DateTime]::UtcNow.AddMilliseconds($TimeoutMs)

    while ([DateTime]::UtcNow -lt $deadline) {
        $remaining = [Math]::Max(1, [int]($deadline - [DateTime]::UtcNow).TotalMilliseconds)
        $message = Read-IpcObject -TimeoutMs $remaining
        if ($null -ne $message.request_id -and [int]$message.request_id -eq $id) {
            if ($message.error -ne 'success') {
                throw 'IPC command failed.'
            }
            return $message.data
        }
    }

    throw 'IPC command response timed out.'
}

function Get-MpvProperty {
    param([Parameter(Mandatory = $true)][string]$Name)

    try {
        return Send-IpcCommand -Command @('get_property', $Name)
    } catch {
        return $null
    }
}

function Convert-ToFiniteDouble {
    param($Value)

    if ($null -eq $Value) {
        return $null
    }

    $number = 0.0
    if (-not [double]::TryParse(
        [string]$Value,
        [Globalization.NumberStyles]::Float,
        [Globalization.CultureInfo]::InvariantCulture,
        [ref]$number
    )) {
        return $null
    }

    if ([double]::IsNaN($number) -or [double]::IsInfinity($number)) {
        return $null
    }

    return $number
}

function Get-DurationSnapshot {
    param([string]$Moment)

    $durationRaw = Get-MpvProperty -Name 'duration'
    $timeRaw = Get-MpvProperty -Name 'time-pos'
    $duration = Convert-ToFiniteDouble -Value $durationRaw
    $timePosition = Convert-ToFiniteDouble -Value $timeRaw

    $durationClass = if ($null -eq $durationRaw) {
        'unknown'
    } elseif ($null -ne $duration -and $duration -gt 0) {
        'finite'
    } elseif ($null -ne $duration -and $duration -eq 0) {
        'zero'
    } elseif ([string]$durationRaw -match '(?i)inf') {
        'infinite'
    } else {
        'unknown'
    }

    return [ordered]@{
        moment = $Moment
        duration_class = $durationClass
        duration_seconds = if ($null -ne $duration) { [Math]::Round($duration, 3) } else { $null }
        time_pos_seconds = if ($null -ne $timePosition) { [Math]::Round($timePosition, 3) } else { $null }
    }
}

function Wait-UntilOffset {
    param(
        [Diagnostics.Stopwatch]$Clock,
        [int]$Seconds
    )

    $remaining = ($Seconds * 1000) - $Clock.ElapsedMilliseconds
    if ($remaining -gt 0) {
        Start-Sleep -Milliseconds $remaining
    }
}

function Invoke-AbsoluteSeek {
    param(
        [double]$Target,
        [string]$Label
    )

    $clock = [Diagnostics.Stopwatch]::StartNew()
    $null = Send-IpcCommand -Command @('seek', $Target, 'absolute+exact') -TimeoutMs 5000
    $success = $false
    $playbackContinued = $false
    $observed = $null

    while ($clock.ElapsedMilliseconds -lt 12000) {
        Start-Sleep -Milliseconds 250
        $observed = Convert-ToFiniteDouble -Value (Get-MpvProperty -Name 'time-pos')
        $seeking = Get-MpvProperty -Name 'seeking'
        if ($null -ne $observed -and [Math]::Abs($observed - $Target) -le 3.0 -and $seeking -ne $true) {
            $settledAt = $observed
            Start-Sleep -Seconds 1
            $continuedAt = Convert-ToFiniteDouble -Value (Get-MpvProperty -Name 'time-pos')
            $playbackContinued = $null -ne $continuedAt -and ($continuedAt - $settledAt) -gt 0.5
            $success = $playbackContinued
            $observed = $continuedAt
            break
        }
    }

    $clock.Stop()
    return [ordered]@{
        label = $Label
        attempted = $true
        success = $success
        playback_continued = $playbackContinued
        target_seconds = [Math]::Round($Target, 3)
        observed_seconds = if ($null -ne $observed) { [Math]::Round($observed, 3) } else { $null }
        latency_ms = $clock.ElapsedMilliseconds
    }
}

$result = [ordered]@{
    status = 'failed'
    source = 'official-ynotv-installation-sidecar'
    candidate = 'VOD_1'
    url_transport = 'named-pipe-ipc-only'
}

try {
    $repoRoot = (Resolve-Path (Join-Path $PSScriptRoot '..\..\..')).Path
    $candidateMapPath = Join-Path $repoRoot '.iptv-spike-candidates.json'
    if (-not (Test-Path -LiteralPath $candidateMapPath -PathType Leaf)) {
        throw 'Private candidate map is unavailable.'
    }

    $candidateMap = Get-Content -LiteralPath $candidateMapPath -Raw | ConvertFrom-Json
    $vodEntry = $candidateMap.candidates.PSObject.Properties.Value |
        Where-Object { $_.label -eq 'VOD_1' } |
        Select-Object -First 1
    if ($null -eq $vodEntry -or [string]::IsNullOrWhiteSpace([string]$vodEntry.url)) {
        throw 'VOD_1 is unavailable in the private candidate map.'
    }
    $vodUrl = [string]$vodEntry.url

    $script:failureStage = 'runtime-discovery'
    $mpvPath = Find-YnoTvMpv
    if ([string]::IsNullOrWhiteSpace($mpvPath)) {
        throw 'ynoTV mpv sidecar is unavailable.'
    }

    $pipeName = 'semyra-mpv-' + ([Guid]::NewGuid().ToString('N'))
    $ipcPath = '\\.\pipe\' + $pipeName
    $arguments = @(
        '--idle=yes',
        '--force-window=no',
        '--no-terminal',
        '--no-config',
        '--msg-level=all=no',
        '--vo=null',
        '--ao=null',
        ('--input-ipc-server=' + $ipcPath)
    )

    $script:failureStage = 'runtime-start'
    $script:mpvProcess = Start-Process -FilePath $mpvPath -ArgumentList $arguments -PassThru -WindowStyle Hidden

    $pipe = [IO.Pipes.NamedPipeClientStream]::new(
        '.',
        $pipeName,
        [IO.Pipes.PipeDirection]::InOut,
        [IO.Pipes.PipeOptions]::None
    )
    $pipe.Connect(10000)
    $utf8 = [Text.UTF8Encoding]::new($false)
    $script:reader = [IO.StreamReader]::new($pipe, $utf8, $false, 4096, $true)
    $script:writer = [IO.StreamWriter]::new($pipe, $utf8, 4096, $true)
    $script:writer.AutoFlush = $true

    $script:failureStage = 'media-load'
    $null = Send-IpcCommand -Command @('loadfile', $vodUrl, 'replace') -TimeoutMs 8000
    $loadClock = [Diagnostics.Stopwatch]::StartNew()
    $mediaLoaded = $script:fileLoadedSeen
    while (-not $mediaLoaded -and $loadClock.ElapsedMilliseconds -lt 30000) {
        $coreIdle = Get-MpvProperty -Name 'core-idle'
        $fileFormat = Get-MpvProperty -Name 'file-format'
        $timePosition = Convert-ToFiniteDouble -Value (Get-MpvProperty -Name 'time-pos')
        $mediaLoaded = $script:fileLoadedSeen -or (($coreIdle -eq $false) -and ($null -ne $fileFormat -or $null -ne $timePosition))
        if (-not $mediaLoaded) {
            Start-Sleep -Milliseconds 500
        }
    }
    if (-not $mediaLoaded) {
        throw 'Media load timed out.'
    }

    $script:failureStage = 'observation'
    $observationClock = [Diagnostics.Stopwatch]::StartNew()
    $snapshots = @()
    $snapshots += Get-DurationSnapshot -Moment 'load'
    foreach ($offset in @(2, 5, 10, 30)) {
        Wait-UntilOffset -Clock $observationClock -Seconds $offset
        $snapshots += Get-DurationSnapshot -Moment ($offset.ToString() + 's')
    }

    $positions = @($snapshots | ForEach-Object { $_.time_pos_seconds } | Where-Object { $null -ne $_ })
    $playbackAdvanced = $positions.Count -ge 2 -and (($positions[-1] - $positions[0]) -gt 5)

    $durationDiscovery = 'not-discovered'
    foreach ($snapshot in $snapshots) {
        if ($snapshot.duration_class -eq 'finite') {
            $durationDiscovery = $snapshot.moment
            break
        }
    }

    $script:failureStage = 'pause-resume'
    $beforePause = Convert-ToFiniteDouble -Value (Get-MpvProperty -Name 'time-pos')
    $null = Send-IpcCommand -Command @('set_property', 'pause', $true)
    Start-Sleep -Seconds 5
    $duringPause = Convert-ToFiniteDouble -Value (Get-MpvProperty -Name 'time-pos')
    $null = Send-IpcCommand -Command @('set_property', 'pause', $false)
    Start-Sleep -Seconds 3
    $afterResume = Convert-ToFiniteDouble -Value (Get-MpvProperty -Name 'time-pos')
    $pauseHeld = $null -ne $beforePause -and $null -ne $duringPause -and [Math]::Abs($duringPause - $beforePause) -le 1.5
    $resumeAdvanced = $null -ne $duringPause -and $null -ne $afterResume -and ($afterResume - $duringPause) -gt 1

    $script:failureStage = 'seeking'
    $seekable = Get-MpvProperty -Name 'seekable'
    $partiallySeekable = Get-MpvProperty -Name 'partially-seekable'
    $positionBeforeSeek = Convert-ToFiniteDouble -Value (Get-MpvProperty -Name 'time-pos')
    $seekPlus30 = if ($null -ne $positionBeforeSeek) {
        Invoke-AbsoluteSeek -Target ($positionBeforeSeek + 30) -Label 'plus-30'
    } else {
        [ordered]@{ label = 'plus-30'; attempted = $false; success = $false; target_seconds = $null; observed_seconds = $null; latency_ms = $null }
    }

    $finiteDuration = $null
    foreach ($snapshot in $snapshots) {
        if ($snapshot.duration_class -eq 'finite') {
            $finiteDuration = [double]$snapshot.duration_seconds
        }
    }
    $seekMidpoint = if ($null -ne $finiteDuration -and $finiteDuration -gt 60) {
        Invoke-AbsoluteSeek -Target ($finiteDuration / 2) -Label 'midpoint'
    } else {
        [ordered]@{ label = 'midpoint'; attempted = $false; success = $false; target_seconds = $null; observed_seconds = $null; latency_ms = $null }
    }

    $priorSeekWorked = $seekPlus30.success -eq $true -or $seekMidpoint.success -eq $true
    $beforeBackward = if ($priorSeekWorked) { Convert-ToFiniteDouble -Value (Get-MpvProperty -Name 'time-pos') } else { $null }
    $seekBackward = if ($priorSeekWorked -and $null -ne $beforeBackward -and $beforeBackward -gt 10) {
        Invoke-AbsoluteSeek -Target ([Math]::Max(0, $beforeBackward - 30)) -Label 'backward-30'
    } else {
        [ordered]@{ label = 'backward-30'; attempted = $false; success = $false; target_seconds = $null; observed_seconds = $null; latency_ms = $null }
    }

    $result.status = 'completed'
    $result.duration_snapshots = $snapshots
    $result.duration_discovery = $durationDiscovery
    $result.playback_advanced = $playbackAdvanced
    $result.file_format = Get-MpvProperty -Name 'file-format'
    $result.video_format = Get-MpvProperty -Name 'video-format'
    $result.video_codec = Get-MpvProperty -Name 'video-codec'
    $result.audio_codec = Get-MpvProperty -Name 'audio-codec'
    $result.width = Get-MpvProperty -Name 'width'
    $result.height = Get-MpvProperty -Name 'height'
    $result.container_fps = Get-MpvProperty -Name 'container-fps'
    $result.demuxer_start_time = Get-MpvProperty -Name 'demuxer-start-time'
    $result.demuxer_cache_duration = Get-MpvProperty -Name 'demuxer-cache-duration'
    $result.cache_duration = Get-MpvProperty -Name 'cache-duration'
    $result.estimated_vf_fps = Get-MpvProperty -Name 'estimated-vf-fps'
    $result.seekable = $seekable
    $result.partially_seekable = $partiallySeekable
    $result.pause_resume = [ordered]@{
        pause_held = $pauseHeld
        resume_advanced = $resumeAdvanced
        success = $pauseHeld -and $resumeAdvanced
    }
    $result.seek_plus_30 = $seekPlus30
    $result.seek_midpoint = $seekMidpoint
    $result.seek_backward = $seekBackward
} catch {
    $result.status = 'failed'
    $result.failure_stage = $script:failureStage
    $result.failure_class = $_.Exception.GetType().Name
} finally {
    if ($null -ne $script:writer) {
        try { $null = Send-IpcCommand -Command @('quit') -TimeoutMs 2000 } catch {}
        $script:writer.Dispose()
    }
    if ($null -ne $script:reader) {
        $script:reader.Dispose()
    }
    if ($null -ne $pipe) {
        $pipe.Dispose()
    }
    if ($null -ne $script:mpvProcess) {
        try {
            if (-not $script:mpvProcess.WaitForExit(5000)) {
                Stop-Process -Id $script:mpvProcess.Id -Force -ErrorAction SilentlyContinue
            }
        } catch {}
    }
}

$result | ConvertTo-Json -Depth 8
