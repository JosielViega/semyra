using System.Globalization;
using System.Security.Cryptography;
using System.Text.Json;
using Semyra.Desktop.Desktop;
using Semyra.Desktop.Iptv;

namespace Semyra.Desktop.Media;

internal sealed record MpvGeometry(double X, double Y, double Width, double Height, double ViewportWidth, double ViewportHeight, bool Visible = true)
{
    internal bool IsValid =>
        double.IsFinite(X) && double.IsFinite(Y) && double.IsFinite(Width) && double.IsFinite(Height)
        && double.IsFinite(ViewportWidth) && double.IsFinite(ViewportHeight)
        && X >= 0 && Y >= 0 && Width >= 0 && Height >= 0
        && ViewportWidth >= 1 && ViewportHeight >= 1
        && (Visible ? Width >= 1 && Height >= 1 : Width == 0 && Height == 0)
        && X + Width <= ViewportWidth + 1 && Y + Height <= ViewportHeight + 1;
}

internal sealed class MpvPlayer : IAsyncDisposable
{
    private readonly MpvRuntime _runtime;
    private readonly nint _hostHandle;
    private readonly Func<MpvRuntime, nint, IMpvProcess> _processFactory;
    private readonly SemaphoreSlim _gate = new(1, 1);
    private readonly object _snapshotLock = new();
    private IMpvProcess? _process;
    private MediaSnapshot _snapshot = MediaSnapshot.Idle;
    private bool _acceptProperties;
    private bool _durationObserved;
    private bool _seekableObserved;
    private bool _progressConfirmed;
    private bool _playbackRestarted;
    private bool _hasVideo;
    private bool _buffering;
    private bool _videoVisible;
    private bool _eofReached;
    private bool _eofSignalEmitted;
    private double _volume = 100;
    private bool _muted;
    private long? _activePlaylistEntryId;
    private double _pendingResumePosition;
    private bool _preserveFullscreenForLoad;
    private bool _disposed;

    internal MpvPlayer(
        MpvRuntime runtime,
        nint hostHandle,
        Func<MpvRuntime, nint, IMpvProcess>? processFactory = null)
    {
        _runtime = runtime;
        _hostHandle = hostHandle;
        _processFactory = processFactory ?? ((value, handle) => new MpvProcess(value, handle));
    }

    internal event EventHandler<MediaSnapshot>? StateChanged;
    internal event EventHandler<bool>? VisibilityChanged;
    internal event EventHandler<long>? PlaybackConfirmed;
    internal string? CurrentSessionId => Snapshot().SessionId;

    internal MediaSnapshot Snapshot()
    {
        lock (_snapshotLock) return _snapshot;
    }

    internal async Task<MpvPlaybackStartResult> StartAsync(
        IptvPlaybackChannel channel,
        CancellationToken cancellationToken,
        double resumePosition = 0,
        bool preserveFullscreen = false)
    {
        await _gate.WaitAsync(cancellationToken);
        try
        {
            ObjectDisposedException.ThrowIf(_disposed, this);
            if (!_runtime.Available || _hostHandle == 0)
            {
                throw new MediaEngineException("mpv_runtime_unavailable");
            }

            var coldStart = _process is null || !_process.IsRunning;
            var sessionId = Convert.ToHexString(RandomNumberGenerator.GetBytes(16)).ToLowerInvariant();
            _preserveFullscreenForLoad = preserveFullscreen && Snapshot().Fullscreen;
            _acceptProperties = false;
            _durationObserved = false;
            _seekableObserved = false;
            _progressConfirmed = false;
            _playbackRestarted = false;
            _hasVideo = false;
            _buffering = false;
            _activePlaylistEntryId = null;
            _pendingResumePosition = double.IsFinite(resumePosition) && resumePosition >= 5 ? resumePosition : 0;
            SetVideoVisibility(false);
            if (coldStart)
            {
                await DisposeProcessAsync();
                _process = _processFactory(_runtime, _hostHandle);
                _process.MessageReceived += OnMessageReceived;
                _process.Exited += OnProcessExited;
                await _process.StartAsync(cancellationToken);
                await SyncAudioPropertiesAsync(_process, cancellationToken);
                await ObserveAsync(_process, cancellationToken);
            }
            else
            {
                // Stop first so clearing pause cannot resume the outgoing item before replacement.
                await _process!.CommandAsync("stop", [], cancellationToken);
            }

            ResetEofState();
            // MPV properties survive while the idle process stays warm. Every new item starts unpaused.
            Update("loading", channel.Id, channel.Name, null, sessionId, _preserveFullscreenForLoad);
            await _process!.CommandAsync("set_property", ["pause", false], cancellationToken);
            // The provider URL exists only in native memory and this IPC write. It is never logged or returned.
            await _process!.CommandAsync("loadfile", [channel.StreamUri.AbsoluteUri, "replace"], cancellationToken);
            var snapshot = Update("loading", channel.Id, channel.Name, null, sessionId, _preserveFullscreenForLoad);
            return new MpvPlaybackStartResult(snapshot, "native-mpv", sessionId);
        }
        catch
        {
            await DisposeProcessAsync();
            SetVideoVisibility(false);
            throw;
        }
        finally
        {
            _gate.Release();
        }
    }

    internal async Task<MediaSnapshot> ControlAsync(string sessionId, string command, double? value, CancellationToken cancellationToken)
    {
        await _gate.WaitAsync(cancellationToken);
        try
        {
            var current = Snapshot();
            if (_process is null || !_process.IsRunning || !FixedEquals(current.SessionId, sessionId))
            {
                throw new MediaEngineException("playback_session_changed");
            }
            switch (command)
            {
                case "pause":
                    if (current.MediaKind != "vod") throw new MediaEngineException("invalid_player_control");
                    await _process.CommandAsync("set_property", ["pause", true], cancellationToken);
                    return await ReadCanonicalControlStateAsync(_process, cancellationToken);
                case "play":
                    if (current.MediaKind != "vod") throw new MediaEngineException("invalid_player_control");
                    await _process.CommandAsync("set_property", ["pause", false], cancellationToken);
                    return await ReadCanonicalControlStateAsync(_process, cancellationToken);
                case "seek-relative":
                    if (value is null || !double.IsFinite(value.Value) || Math.Abs(value.Value) > 86400 || current.MediaKind != "vod" || !current.Seekable)
                        throw new MediaEngineException("invalid_player_control");
                    var relativeTarget = Math.Clamp(current.Position + value.Value, 0, current.Duration);
                    await _process.CommandAsync("seek", [relativeTarget, "absolute+exact"], cancellationToken);
                    return current;
                case "seek-absolute":
                    if (value is null || !double.IsFinite(value.Value) || current.MediaKind != "vod" || !current.Seekable)
                        throw new MediaEngineException("invalid_player_control");
                    var absoluteTarget = Math.Clamp(value.Value, 0, current.Duration);
                    await _process.CommandAsync("seek", [absoluteTarget, "absolute+exact"], cancellationToken);
                    return current;
                case "mute":
                    await _process.CommandAsync("set_property", ["mute", true], cancellationToken);
                    return await ReadCanonicalControlStateAsync(_process, cancellationToken);
                case "unmute":
                    await _process.CommandAsync("set_property", ["mute", false], cancellationToken);
                    return await ReadCanonicalControlStateAsync(_process, cancellationToken);
                case "volume":
                    if (value is null || !double.IsFinite(value.Value) || value is < 0 or > 100)
                        throw new MediaEngineException("invalid_player_control");
                    await _process.CommandAsync("set_property", ["volume", value.Value], cancellationToken);
                    return await ReadCanonicalControlStateAsync(_process, cancellationToken);
                case "stop":
                    return await StopCoreAsync(cancellationToken);
                default:
                    throw new MediaEngineException("invalid_player_control");
            }
        }
        finally
        {
            _gate.Release();
        }
    }

    internal async Task<MediaSnapshot> StopAsync()
    {
        await _gate.WaitAsync();
        try
        {
            return await StopCoreAsync(CancellationToken.None);
        }
        finally
        {
            _gate.Release();
        }
    }

    private async Task ObserveAsync(IMpvProcess process, CancellationToken cancellationToken)
    {
        string[] properties = ["pause", "time-pos", "duration", "seekable", "mute", "volume", "paused-for-cache", "file-format", "video-format", "video-codec", "audio-codec-name", "vid", "track-list", "eof-reached"];
        for (var index = 0; index < properties.Length; index++)
        {
            await process.CommandAsync("observe_property", [index + 1, properties[index]], cancellationToken);
        }
    }

    private async Task SyncAudioPropertiesAsync(IMpvProcess process, CancellationToken cancellationToken)
    {
        var volume = await process.CommandAsync("get_property", ["volume"], cancellationToken);
        if (volume.TryGetProperty("data", out var volumeData) && volumeData.TryGetDouble(out var value))
            _volume = Math.Clamp(Finite(value), 0, 100);

        var muted = await process.CommandAsync("get_property", ["mute"], cancellationToken);
        if (muted.TryGetProperty("data", out var mutedData) && mutedData.ValueKind is JsonValueKind.True or JsonValueKind.False)
            _muted = mutedData.GetBoolean();
    }

    private async Task<MediaSnapshot> ReadCanonicalControlStateAsync(IMpvProcess process, CancellationToken cancellationToken)
    {
        var pauseResponse = await process.CommandAsync("get_property", ["pause"], cancellationToken);
        var muteResponse = await process.CommandAsync("get_property", ["mute"], cancellationToken);
        var volumeResponse = await process.CommandAsync("get_property", ["volume"], cancellationToken);
        var bufferingResponse = await process.CommandAsync("get_property", ["paused-for-cache"], cancellationToken);
        var positionResponse = await process.CommandAsync("get_property", ["time-pos"], cancellationToken);
        var durationResponse = await process.CommandAsync("get_property", ["duration"], cancellationToken);

        var current = Snapshot();
        var paused = BooleanData(pauseResponse) ?? current.Paused;
        _muted = BooleanData(muteResponse) ?? _muted;
        _buffering = BooleanData(bufferingResponse) ?? _buffering;
        if (NumberData(volumeResponse) is { } volume) _volume = Math.Clamp(volume, 0, 100);
        var position = NumberData(positionResponse) ?? current.Position;
        var duration = NumberData(durationResponse) ?? current.Duration;
        var state = current.State;
        if (state is "playing" or "paused" or "buffering")
            state = _buffering ? "buffering" : paused ? "paused" : "playing";

        return UpdateFrom(current with
        {
            State = state,
            Paused = paused,
            Muted = _muted,
            Volume = _volume,
            Buffering = _buffering,
            Position = Finite(position),
            Duration = Finite(duration),
        });
    }

    private static bool? BooleanData(JsonElement response) =>
        response.TryGetProperty("data", out var data) && data.ValueKind is JsonValueKind.True or JsonValueKind.False
            ? data.GetBoolean()
            : null;

    private static double? NumberData(JsonElement response) =>
        response.TryGetProperty("data", out var data) && data.TryGetDouble(out var value) ? value : null;

    private void OnMessageReceived(object? sender, JsonElement message)
    {
        try
        {
            if (message.TryGetProperty("event", out var eventElement))
            {
                var eventName = eventElement.GetString();
                if (eventName == "start-file")
                {
                    ResetEofState();
                    _acceptProperties = true;
                    _activePlaylistEntryId = PlaylistEntryId(message);
                    var current = Snapshot();
                    UpdateFrom(current with
                    {
                        State = "loading", LastErrorCode = null, Position = 0, Duration = 0,
                        Seekable = false, MediaKind = null, Fullscreen = _preserveFullscreenForLoad, Paused = false,
                    });
                }
                else if (eventName == "file-loaded")
                {
                    if (!MatchesActiveEntry(message)) return;
                    UpdateFrom(Snapshot());
                }
                else if (eventName == "playback-restart")
                {
                    if (!_acceptProperties || !MatchesActiveEntry(message)) return;
                    _playbackRestarted = true;
                    var current = Snapshot();
                    var playing = UpdateFrom(current with
                    {
                        State = _buffering ? "buffering" : current.Paused ? "paused" : "playing",
                        LastErrorCode = null,
                    });
                    ShowVideoIfReady();
                    ConfirmProgress(playing);
                    QueueResumeIfReady(playing);
                }
                else if (eventName == "end-file")
                {
                    if (!_acceptProperties || !MatchesActiveEntry(message)) return;
                    var reason = message.TryGetProperty("reason", out var reasonElement) ? reasonElement.GetString() : null;
                    if (reason == "eof")
                    {
                        HandleNaturalEof();
                        return;
                    }
                    var current = Snapshot();
                    _acceptProperties = false;
                    SetVideoVisibility(false);
                    UpdateFrom(current with
                    {
                        State = "failed",
                        LastErrorCode = ClassifyEndFile(message),
                    });
                }
                else if (eventName == "property-change")
                {
                    ApplyProperty(message);
                }
            }
        }
        catch
        {
            // Protocol errors become a controlled failure without serializing the original message.
            var current = Snapshot();
            UpdateFrom(current with { State = "failed", LastErrorCode = "mpv_protocol_error" });
        }
    }

    private void ApplyProperty(JsonElement message)
    {
        if (!message.TryGetProperty("name", out var nameElement) || !message.TryGetProperty("data", out var data)) return;
        var name = nameElement.GetString();
        if (name == "volume" && data.TryGetDouble(out var observedVolume))
            _volume = Math.Clamp(Finite(observedVolume), 0, 100);
        if (name == "mute" && data.ValueKind is JsonValueKind.True or JsonValueKind.False)
            _muted = data.GetBoolean();
        if (!_acceptProperties) return;
        if (name == "eof-reached" && data.ValueKind is JsonValueKind.True or JsonValueKind.False)
        {
            var reached = data.GetBoolean();
            var transitionedToEof = !_eofReached && reached;
            _eofReached = reached;
            if (transitionedToEof) HandleNaturalEof();
            return;
        }
        var current = Snapshot();
        if (name == "duration") _durationObserved = true;
        if (name == "seekable") _seekableObserved = true;
        if (name == "paused-for-cache" && data.ValueKind is JsonValueKind.True or JsonValueKind.False)
            _buffering = data.GetBoolean();
        if (name is "video-format" or "video-codec" && SafeProperty(data) is not null) _hasVideo = true;
        if (name == "vid") _hasVideo = HasVideoId(data);
        if (name == "track-list" && data.ValueKind == JsonValueKind.Array) _hasVideo = HasSelectedVideoTrack(data);
        var next = name switch
        {
            "pause" when data.ValueKind is JsonValueKind.True or JsonValueKind.False => current with
            {
                Paused = data.GetBoolean(),
                State = _buffering ? "buffering" : data.GetBoolean() ? "paused" : current.State == "paused" ? "playing" : current.State,
            },
            "paused-for-cache" when data.ValueKind == JsonValueKind.True => current with { State = "buffering" },
            "paused-for-cache" when data.ValueKind == JsonValueKind.False && current.State == "buffering" => current with { State = current.Paused ? "paused" : "playing" },
            "time-pos" when data.TryGetDouble(out var position) => current with { Position = Finite(position) },
            "duration" => current with { Duration = data.TryGetDouble(out var duration) ? Finite(duration) : 0 },
            "seekable" when data.ValueKind is JsonValueKind.True or JsonValueKind.False => current with { Seekable = data.GetBoolean() },
            "mute" when data.ValueKind is JsonValueKind.True or JsonValueKind.False => current with { Muted = _muted },
            "volume" when data.TryGetDouble(out _) => current with { Volume = _volume },
            "file-format" => current with { Container = SafeProperty(data) },
            "video-format" or "video-codec" => current with { VideoCodec = SafeProperty(data) },
            "audio-codec-name" => current with { AudioCodec = SafeProperty(data) },
            _ => current,
        };
        if (next != current)
        {
            next = next with { Buffering = _buffering };
            next = UpdateFrom(next);
            ShowVideoIfReady();
            ConfirmProgress(next);
            QueueResumeIfReady(next);
        }
    }

    private void QueueResumeIfReady(MediaSnapshot snapshot)
    {
        if (_pendingResumePosition < 5 || snapshot.MediaKind != "vod" || !snapshot.Seekable || snapshot.Duration <= 0 || _process is null) return;
        var target = Math.Clamp(_pendingResumePosition, 0, Math.Max(0, snapshot.Duration - 5));
        _pendingResumePosition = 0;
        if (target < 5) return;
        var process = _process;
        _ = Task.Run(async () =>
        {
            try { await process.CommandAsync("seek", [target, "absolute+exact"], CancellationToken.None); }
            catch { }
        });
    }

    private void OnProcessExited(object? sender, EventArgs e)
    {
        if (_disposed || Snapshot().State == "idle") return;
        SetVideoVisibility(false);
        var current = Snapshot();
        UpdateFrom(current with { State = "failed", LastErrorCode = "mpv_process_error" });
        if (sender is IMpvProcess process) _ = DisposeExitedProcessAsync(process);
    }

    private async Task DisposeExitedProcessAsync(IMpvProcess process)
    {
        await _gate.WaitAsync();
        try
        {
            if (!ReferenceEquals(_process, process)) return;
            await DisposeProcessAsync();
        }
        finally
        {
            _gate.Release();
        }
    }

    private MediaSnapshot Update(string state, long channelId, string channelName, string? errorCode, string sessionId, bool fullscreen = false) =>
        UpdateFrom(new MediaSnapshot(
            state, channelId, channelName, 1, errorCode, "view", "native-mpv", sessionId,
            Muted: _muted, Volume: _volume, Fullscreen: fullscreen, Paused: false));

    private MediaSnapshot UpdateFrom(MediaSnapshot snapshot)
    {
        if (snapshot.PlaybackKind == "native-mpv")
        {
            snapshot = snapshot with
            {
                MediaKind = (snapshot.State is "playing" or "paused" or "buffering") && _durationObserved && _seekableObserved
                    ? snapshot.Duration > 1 && snapshot.Seekable ? "vod" : "live"
                    : snapshot.MediaKind,
            };
        }
        lock (_snapshotLock) _snapshot = snapshot;
        StateChanged?.Invoke(this, snapshot);
        return snapshot;
    }

    private async Task DisposeProcessAsync()
    {
        ResetEofState();
        var process = _process;
        _process = null;
        if (process is null) return;
        process.MessageReceived -= OnMessageReceived;
        process.Exited -= OnProcessExited;
        await process.DisposeAsync();
    }

    internal MediaSnapshot SetFullscreen(string sessionId, bool fullscreen)
    {
        var current = Snapshot();
        if (!FixedEquals(current.SessionId, sessionId))
            throw new MediaEngineException("playback_session_changed");
        return UpdateFrom(current with { Fullscreen = fullscreen });
    }

    private async Task<MediaSnapshot> StopCoreAsync(CancellationToken cancellationToken)
    {
        _acceptProperties = false;
        ResetEofState();
        _pendingResumePosition = 0;
        _preserveFullscreenForLoad = false;
        if (_process is { IsRunning: true })
        {
            try { await _process.CommandAsync("stop", [], cancellationToken); }
            catch (MediaEngineException) { await DisposeProcessAsync(); }
        }
        SetVideoVisibility(false);
        return UpdateFrom(MediaSnapshot.Idle);
    }

    private void ConfirmProgress(MediaSnapshot snapshot)
    {
        if (_progressConfirmed || snapshot.Position <= 0 || snapshot.ChannelId is not long channelId) return;
        if (snapshot.State is not ("playing" or "paused" or "buffering")) return;
        _progressConfirmed = true;
        PlaybackConfirmed?.Invoke(this, channelId);
    }

    private void HandleNaturalEof()
    {
        if (_eofSignalEmitted) return;
        _eofSignalEmitted = true;
        _acceptProperties = false;
        var current = Snapshot();
        SetVideoVisibility(false);
        UpdateFrom(current with
        {
            State = "ended",
            LastErrorCode = null,
            Paused = true,
            Position = current.Duration > 0 ? current.Duration : current.Position,
        });
    }

    private void ResetEofState()
    {
        _eofReached = false;
        _eofSignalEmitted = false;
    }

    private void ShowVideoIfReady()
    {
        if (_acceptProperties && _playbackRestarted && _hasVideo) SetVideoVisibility(true);
    }

    private void SetVideoVisibility(bool visible)
    {
        if (_videoVisible == visible) return;
        _videoVisible = visible;
        VisibilityChanged?.Invoke(this, visible);
    }

    private bool MatchesActiveEntry(JsonElement message)
    {
        var entryId = PlaylistEntryId(message);
        return entryId is null || _activePlaylistEntryId is null || entryId == _activePlaylistEntryId;
    }

    private static long? PlaylistEntryId(JsonElement message) =>
        message.TryGetProperty("playlist_entry_id", out var value) && value.TryGetInt64(out var id) ? id : null;

    private static bool HasVideoId(JsonElement data) => data.ValueKind switch
    {
        JsonValueKind.Number => data.TryGetInt64(out var id) && id > 0,
        JsonValueKind.String => data.GetString() is { } value
            && !string.Equals(value, "no", StringComparison.OrdinalIgnoreCase)
            && !string.Equals(value, "auto", StringComparison.OrdinalIgnoreCase),
        _ => false,
    };

    private static bool HasSelectedVideoTrack(JsonElement tracks)
    {
        foreach (var track in tracks.EnumerateArray())
        {
            if (!track.TryGetProperty("type", out var type) || type.GetString() != "video") continue;
            if (!track.TryGetProperty("selected", out var selected) || selected.ValueKind == JsonValueKind.True) return true;
        }
        return false;
    }

    private static string ClassifyEndFile(JsonElement message)
    {
        var error = message.TryGetProperty("file_error", out var fileError) ? fileError.GetString()
            : message.TryGetProperty("error", out var genericError) ? genericError.GetString()
            : null;
        var safe = error?.ToLowerInvariant() ?? string.Empty;
        if (safe.Contains("http", StringComparison.Ordinal)) return "mpv_http_error";
        if (safe.Contains("timeout", StringComparison.Ordinal) || safe.Contains("timed out", StringComparison.Ordinal)) return "mpv_timeout";
        if (safe.Contains("codec", StringComparison.Ordinal) || safe.Contains("decoder", StringComparison.Ordinal)) return "mpv_codec_error";
        if (safe.Contains("demux", StringComparison.Ordinal) || safe.Contains("format", StringComparison.Ordinal)) return "mpv_demux_error";
        return "mpv_end_file_error";
    }

    private static bool FixedEquals(string? left, string? right)
    {
        if (left is null || right is null || left.Length != right.Length) return false;
        return CryptographicOperations.FixedTimeEquals(
            System.Text.Encoding.ASCII.GetBytes(left),
            System.Text.Encoding.ASCII.GetBytes(right));
    }

    private static double Finite(double value) => double.IsFinite(value) && value >= 0 ? value : 0;
    private static string? SafeProperty(JsonElement value)
    {
        if (value.ValueKind != JsonValueKind.String) return null;
        var raw = value.GetString();
        if (string.IsNullOrWhiteSpace(raw)) return null;
        var safe = new string(raw.Where(character => char.IsAsciiLetterOrDigit(character) || character is '-' or '_' or '.' or '/').Take(64).ToArray());
        return safe.Length == 0 ? null : safe;
    }

    public async ValueTask DisposeAsync()
    {
        if (_disposed) return;
        _disposed = true;
        await _gate.WaitAsync();
        try
        {
            _acceptProperties = false;
            ResetEofState();
            SetVideoVisibility(false);
            await DisposeProcessAsync();
            UpdateFrom(MediaSnapshot.Idle);
        }
        finally
        {
            _gate.Release();
        }
        _gate.Dispose();
    }
}
