using System.Text.Json;
using Semyra.Desktop.Iptv;
using Semyra.Desktop.Media;
using Semyra.Desktop.Desktop;

namespace Semyra.Desktop.Tests;

public sealed class MpvPlayerTests
{
    [Fact]
    public async Task StartSendsProviderUrlOnlyThroughLoadfileAndKeepsProcessWarm()
    {
        var process = new FakeMpvProcess();
        var created = 0;
        await using var player = Player(() => { created++; return process; });

        var first = await player.StartAsync(Channel(1), default);
        var firstSession = first.SessionId;
        var second = await player.StartAsync(Channel(2), default);

        Assert.Equal(1, created);
        Assert.Equal("native-mpv", first.PlaybackKind);
        Assert.NotEqual(firstSession, second.SessionId);
        Assert.Equal(2, process.Commands.Count(call => call.Command == "loadfile"));
        Assert.Equal(2, process.Commands.Count(call => call.Command == "set_property" && Equals(call.Arguments[0], "pause") && Equals(call.Arguments[1], false)));
        Assert.Single(process.Commands, call => call.Command == "stop");
        Assert.Equal("https://example.invalid/private/1.mp4", process.Commands.First(call => call.Command == "loadfile").Arguments[0]);
        Assert.Equal("replace", process.Commands.First(call => call.Command == "loadfile").Arguments[1]);
        Assert.DoesNotContain(process.Arguments, value => value.Contains("example.invalid", StringComparison.Ordinal));
    }

    [Fact]
    public async Task EveryNewLoadStopsOutgoingMediaThenClearsPauseBeforeLoadfile()
    {
        var process = new FakeMpvProcess();
        await using var player = Player(() => process);

        await player.StartAsync(Channel(1), default);
        process.Emit("""{"event":"start-file","playlist_entry_id":1}""");
        process.Emit("""{"event":"property-change","name":"duration","data":120}""");
        process.Emit("""{"event":"property-change","name":"seekable","data":true}""");
        process.Emit("""{"event":"property-change","name":"pause","data":true}""");
        Assert.True(player.Snapshot().Paused);

        var commandOffset = process.Commands.Count;
        var second = await player.StartAsync(Channel(2), default);
        Assert.False(second.Snapshot.Paused);
        Assert.Equal(["stop", "set_property", "loadfile"], process.Commands.Skip(commandOffset).Select(call => call.Command));
        Assert.Equal("pause", process.Commands[commandOffset + 1].Arguments[0]);
        Assert.Equal(false, process.Commands[commandOffset + 1].Arguments[1]);

        process.Emit("""{"event":"start-file","playlist_entry_id":2}""");
        process.Emit("""{"event":"property-change","name":"duration","data":null}""");
        process.Emit("""{"event":"property-change","name":"seekable","data":false}""");
        process.Emit("""{"event":"playback-restart","playlist_entry_id":2}""");
        Assert.Equal("playing", player.Snapshot().State);
        Assert.Equal("live", player.Snapshot().MediaKind);

        process.Emit("""{"event":"property-change","name":"pause","data":true}""");
        var reopened = await player.StartAsync(Channel(2), default);
        Assert.False(reopened.Snapshot.Paused);
    }

    [Theory]
    [InlineData(100, false)]
    [InlineData(35, true)]
    public async Task InitialSnapshotUsesRealMpvVolumeAndMute(double volume, bool muted)
    {
        var process = new FakeMpvProcess { InitialVolume = volume, InitialMuted = muted };
        await using var player = Player(() => process);

        var started = await player.StartAsync(Channel(1), default);

        Assert.Equal(volume, started.Snapshot.Volume);
        Assert.Equal(muted, started.Snapshot.Muted);
        Assert.Contains(process.Commands, call => call.Command == "get_property" && Equals(call.Arguments[0], "volume"));
        Assert.Contains(process.Commands, call => call.Command == "get_property" && Equals(call.Arguments[0], "mute"));
    }

    [Fact]
    public async Task VolumeAndMutePersistAcrossWarmLoadsWhilePauseDoesNot()
    {
        var process = new FakeMpvProcess { InitialVolume = 35 };
        await using var player = Player(() => process);
        var first = await player.StartAsync(Channel(1), default);
        process.Emit("""{"event":"start-file","playlist_entry_id":1}""");
        process.Emit("""{"event":"property-change","name":"pause","data":true}""");
        await player.ControlAsync(first.SessionId!, "mute", null, default);
        await player.ControlAsync(first.SessionId!, "volume", 70, default);

        var second = await player.StartAsync(Channel(2), default);

        Assert.False(second.Snapshot.Paused);
        Assert.True(second.Snapshot.Muted);
        Assert.Equal(70, second.Snapshot.Volume);
    }

    [Fact]
    public async Task PauseAndResumeCurrentMediaOnlyToggleObservedMpvPauseProperty()
    {
        var process = new FakeMpvProcess();
        await using var player = Player(() => process);
        var started = await player.StartAsync(Channel(1), default);
        process.Emit("""{"event":"start-file","playlist_entry_id":1}""");
        process.Emit("""{"event":"property-change","name":"duration","data":120}""");
        process.Emit("""{"event":"property-change","name":"seekable","data":true}""");
        process.Emit("""{"event":"playback-restart","playlist_entry_id":1}""");
        var commandOffset = process.Commands.Count;

        for (var cycle = 0; cycle < 4; cycle++)
        {
            var paused = await player.ControlAsync(started.SessionId!, "pause", null, default);
            Assert.True(paused.Paused);
            Assert.Equal("paused", paused.State);

            var playing = await player.ControlAsync(started.SessionId!, "play", null, default);
            Assert.False(playing.Paused);
            Assert.Equal("playing", playing.State);
        }

        var allControlCommands = process.Commands.Skip(commandOffset).ToArray();
        var controls = allControlCommands.Where(call => call.Command == "set_property").ToArray();
        Assert.Equal(8, controls.Length);
        Assert.Equal([true, false, true, false, true, false, true, false],
            controls.Select(call => Assert.IsType<bool>(call.Arguments[1])).ToArray());
        Assert.All(controls, call => Assert.Equal("pause", call.Arguments[0]));
        Assert.DoesNotContain(allControlCommands, call => call.Command is "stop" or "loadfile");
        Assert.Equal(started.SessionId, player.Snapshot().SessionId);
        Assert.Equal(1, player.Snapshot().ChannelId);
    }

    [Fact]
    public async Task KeepOpenEofTransitionEndsEachFileExactlyOnceAndResetsForTheNextLoad()
    {
        var process = new FakeMpvProcess();
        await using var player = Player(() => process);
        var ended = 0;
        player.StateChanged += (_, snapshot) =>
        {
            if (snapshot.State == "ended") ended++;
        };

        await player.StartAsync(Channel(1), default);
        Assert.Contains(process.Commands, command => command.Command == "observe_property"
            && command.Arguments.Count == 2 && Equals(command.Arguments[1], "eof-reached"));
        process.Emit("""{"event":"start-file","playlist_entry_id":1}""");
        process.Emit("""{"event":"property-change","name":"duration","data":100}""");
        process.Emit("""{"event":"property-change","name":"time-pos","data":99}""");
        process.Emit("""{"event":"property-change","name":"pause","data":true}""");
        process.Emit("""{"event":"property-change","name":"eof-reached","data":false}""");
        Assert.Equal(0, ended);

        process.Emit("""{"event":"property-change","name":"eof-reached","data":true}""");
        process.Emit("""{"event":"property-change","name":"eof-reached","data":true}""");
        process.Emit("""{"event":"end-file","reason":"eof","playlist_entry_id":1}""");
        Assert.Equal(1, ended);
        Assert.Equal(100, player.Snapshot().Position);

        await player.StartAsync(Channel(2), default);
        process.Emit("""{"event":"start-file","playlist_entry_id":2}""");
        process.Emit("""{"event":"property-change","name":"duration","data":200}""");
        process.Emit("""{"event":"property-change","name":"eof-reached","data":true}""");
        Assert.Equal(2, ended);
        Assert.Equal(200, player.Snapshot().Position);
    }

    [Fact]
    public async Task ControlResponseReadsCanonicalPauseWhenPropertyEventHasNotArrived()
    {
        var process = new FakeMpvProcess { AutoEmitPropertyChanges = false };
        await using var player = Player(() => process);
        var started = await player.StartAsync(Channel(1), default);
        process.Emit("""{"event":"start-file","playlist_entry_id":1}""");
        process.Emit("""{"event":"property-change","name":"duration","data":120}""");
        process.Emit("""{"event":"property-change","name":"seekable","data":true}""");
        process.Emit("""{"event":"playback-restart","playlist_entry_id":1}""");

        var paused = await player.ControlAsync(started.SessionId!, "pause", null, default);

        Assert.True(paused.Paused);
        Assert.Equal("paused", paused.State);
    }

    [Fact]
    public async Task MuteAndUnmuteUseOnlyObservedMpvMuteProperty()
    {
        var process = new FakeMpvProcess();
        await using var player = Player(() => process);
        var started = await player.StartAsync(Channel(1), default);
        process.Emit("""{"event":"start-file","playlist_entry_id":1}""");
        var commandOffset = process.Commands.Count;

        var muted = await player.ControlAsync(started.SessionId!, "mute", null, default);
        var audible = await player.ControlAsync(started.SessionId!, "unmute", null, default);

        Assert.True(muted.Muted);
        Assert.False(audible.Muted);
        var controls = process.Commands.Skip(commandOffset).Where(call => call.Command == "set_property").ToArray();
        Assert.Equal(2, controls.Length);
        Assert.All(controls, call => Assert.Equal("set_property", call.Command));
        Assert.Equal([true, false], controls.Select(call => Assert.IsType<bool>(call.Arguments[1])).ToArray());
        Assert.All(controls, call => Assert.Equal("mute", call.Arguments[0]));
    }

    [Fact]
    public async Task NativeHostRemainsHiddenUntilPlaybackRestartsWithVideo()
    {
        var process = new FakeMpvProcess();
        var visibility = new List<bool>();
        await using var player = Player(() => process);
        player.VisibilityChanged += (_, value) => visibility.Add(value);

        await player.StartAsync(Channel(1), default);
        Assert.DoesNotContain(true, visibility);
        process.Emit("""{"event":"start-file","playlist_entry_id":1}""");
        process.Emit("""{"event":"property-change","name":"video-codec","data":"h264"}""");
        Assert.DoesNotContain(true, visibility);
        process.Emit("""{"event":"playback-restart","playlist_entry_id":1}""");

        Assert.True(visibility[^1]);
    }

    [Fact]
    public async Task EventsProducePlayingBufferingPausedEndedAndSafeMetadata()
    {
        var process = new FakeMpvProcess();
        await using var player = Player(() => process);
        var started = await player.StartAsync(Channel(1), default);
        var confirmed = new List<long>();
        player.PlaybackConfirmed += (_, channelId) => confirmed.Add(channelId);

        process.Emit("""{"event":"start-file"}""");
        process.Emit("""{"event":"file-loaded"}""");
        process.Emit("""{"event":"property-change","name":"seekable","data":true}""");
        process.Emit("""{"event":"property-change","name":"duration","data":125.5}""");
        process.Emit("""{"event":"property-change","name":"file-format","data":"mov,mp4 secret"}""");
        process.Emit("""{"event":"playback-restart"}""");
        Assert.Equal("playing", player.Snapshot().State);
        Assert.Equal("vod", player.Snapshot().MediaKind);
        Assert.Empty(confirmed);
        process.Emit("""{"event":"property-change","name":"time-pos","data":0.25}""");
        Assert.Equal([1L], confirmed);
        Assert.True(player.Snapshot().Seekable);
        Assert.Equal(125.5, player.Snapshot().Duration);
        Assert.Equal("movmp4secret", player.Snapshot().Container);
        process.Emit("""{"event":"property-change","name":"paused-for-cache","data":true}""");
        Assert.Equal("buffering", player.Snapshot().State);
        await player.ControlAsync(started.SessionId!, "pause", null, default);
        Assert.Equal("buffering", player.Snapshot().State);
        process.Emit("""{"event":"property-change","name":"pause","data":true}""");
        Assert.True(player.Snapshot().Paused);
        Assert.Equal("buffering", player.Snapshot().State);
        process.Emit("""{"event":"property-change","name":"paused-for-cache","data":false}""");
        Assert.Equal("paused", player.Snapshot().State);
        process.Emit("""{"event":"end-file","reason":"eof"}""");
        Assert.Equal("ended", player.Snapshot().State);
    }

    [Fact]
    public async Task ControlsAreSessionFencedAndStopKeepsSidecarWarm()
    {
        var process = new FakeMpvProcess();
        await using var player = Player(() => process);
        var started = await player.StartAsync(Channel(1), default);
        process.Emit("""{"event":"start-file"}""");
        process.Emit("""{"event":"property-change","name":"seekable","data":true}""");
        process.Emit("""{"event":"property-change","name":"duration","data":120}""");
        process.Emit("""{"event":"playback-restart"}""");

        var exception = await Assert.ThrowsAsync<MediaEngineException>(() =>
            player.ControlAsync(new string('f', 32), "pause", null, default));
        Assert.Equal("playback_session_changed", exception.Code);

        await player.ControlAsync(started.SessionId!, "seek-relative", 30, default);
        await player.ControlAsync(started.SessionId!, "seek-absolute", 60, default);
        await player.ControlAsync(started.SessionId!, "volume", 42, default);
        await player.ControlAsync(started.SessionId!, "mute", null, default);
        Assert.Contains(process.Commands, call => call.Command == "seek");
        Assert.Equal(42, player.Snapshot().Volume);
        Assert.True(player.Snapshot().Muted);

        var stopped = await player.ControlAsync(started.SessionId!, "stop", null, default);
        Assert.Equal("idle", stopped.State);
        Assert.False(process.Disposed);
        Assert.Contains(process.Commands, call => call.Command == "stop");
    }

    [Fact]
    public async Task UnexpectedProcessExitBecomesControlledFailure()
    {
        var process = new FakeMpvProcess();
        await using var player = Player(() => process);
        await player.StartAsync(Channel(1), default);
        process.Exit();
        Assert.Equal("failed", player.Snapshot().State);
        Assert.Equal("mpv_process_error", player.Snapshot().LastErrorCode);
        await Task.Delay(20);
        Assert.True(process.Disposed);
    }

    [Fact]
    public async Task LiveContextHidesVodSemanticsAndRejectsPauseAndSeek()
    {
        var process = new FakeMpvProcess();
        await using var player = Player(() => process);
        var started = await player.StartAsync(Channel(1), default);
        process.Emit("""{"event":"start-file"}""");
        process.Emit("""{"event":"property-change","name":"duration","data":null}""");
        process.Emit("""{"event":"property-change","name":"seekable","data":false}""");
        process.Emit("""{"event":"playback-restart"}""");

        Assert.Equal("live", player.Snapshot().MediaKind);
        var pause = await Assert.ThrowsAsync<MediaEngineException>(() =>
            player.ControlAsync(started.SessionId!, "pause", null, default));
        var seek = await Assert.ThrowsAsync<MediaEngineException>(() =>
            player.ControlAsync(started.SessionId!, "seek-relative", 30, default));
        Assert.Equal("invalid_player_control", pause.Code);
        Assert.Equal("invalid_player_control", seek.Code);
    }

    [Fact]
    public async Task ReplaceResetsStateAndIgnoresPropertiesUntilNewFileStarts()
    {
        var process = new FakeMpvProcess();
        await using var player = Player(() => process);
        await player.StartAsync(Channel(1), default);
        process.Emit("""{"event":"start-file"}""");
        process.Emit("""{"event":"property-change","name":"duration","data":125}""");
        process.Emit("""{"event":"property-change","name":"seekable","data":true}""");

        var second = await player.StartAsync(Channel(2), default);
        process.Emit("""{"event":"property-change","name":"duration","data":999}""");
        Assert.Equal(2, second.Snapshot.ChannelId);
        Assert.Equal(0, player.Snapshot().Duration);
        Assert.Null(player.Snapshot().MediaKind);
    }

    [Fact]
    public async Task FailedReplacementDoesNotPoisonNextLoadAndStaleErrorIsIgnored()
    {
        var process = new FakeMpvProcess();
        var visibility = new List<bool>();
        await using var player = Player(() => process);
        player.VisibilityChanged += (_, value) => visibility.Add(value);

        await player.StartAsync(Channel(1), default);
        process.Emit("""{"event":"start-file","playlist_entry_id":11}""");
        process.Emit("""{"event":"property-change","name":"vid","data":1}""");
        process.Emit("""{"event":"playback-restart","playlist_entry_id":11}""");
        Assert.Equal("playing", player.Snapshot().State);
        process.Emit("""{"event":"property-change","name":"pause","data":true}""");
        Assert.Equal("paused", player.Snapshot().State);

        await player.StartAsync(Channel(2), default);
        Assert.False(player.Snapshot().Paused);
        process.Emit("""{"event":"start-file","playlist_entry_id":22}""");
        process.Emit("""{"event":"end-file","playlist_entry_id":22,"reason":"error","file_error":"connection timed out"}""");
        Assert.Equal("failed", player.Snapshot().State);
        Assert.Equal("mpv_timeout", player.Snapshot().LastErrorCode);
        Assert.False(visibility[^1]);
        Assert.False(process.Disposed);

        await player.StartAsync(Channel(3), default);
        Assert.False(player.Snapshot().Paused);
        process.Emit("""{"event":"start-file","playlist_entry_id":33}""");
        process.Emit("""{"event":"end-file","playlist_entry_id":22,"reason":"error","file_error":"http error"}""");
        Assert.Equal("loading", player.Snapshot().State);
        process.Emit("""{"event":"property-change","name":"video-codec","data":"hevc"}""");
        process.Emit("""{"event":"playback-restart","playlist_entry_id":33}""");
        Assert.Equal("playing", player.Snapshot().State);
        Assert.Equal(3, player.Snapshot().ChannelId);
        Assert.True(visibility[^1]);
        Assert.Equal(1, process.StartCount);
    }

    [Fact]
    public async Task FirstFailureStillAllowsSecondItemToPlayInSameProcess()
    {
        var process = new FakeMpvProcess();
        await using var player = Player(() => process);

        await player.StartAsync(Channel(1), default);
        process.Emit("""{"event":"start-file","playlist_entry_id":1}""");
        process.Emit("""{"event":"end-file","playlist_entry_id":1,"reason":"error","file_error":"demux failed"}""");
        Assert.Equal("mpv_demux_error", player.Snapshot().LastErrorCode);

        await player.StartAsync(Channel(2), default);
        process.Emit("""{"event":"start-file","playlist_entry_id":2}""");
        process.Emit("""{"event":"property-change","name":"vid","data":1}""");
        process.Emit("""{"event":"playback-restart","playlist_entry_id":2}""");

        Assert.Equal("playing", player.Snapshot().State);
        Assert.Equal(2, player.Snapshot().ChannelId);
        Assert.False(process.Disposed);
        Assert.Equal(1, process.StartCount);
    }

    [Theory]
    [InlineData("HTTP 403", "mpv_http_error")]
    [InlineData("connection timed out", "mpv_timeout")]
    [InlineData("codec decoder unavailable", "mpv_codec_error")]
    [InlineData("demux format unsupported", "mpv_demux_error")]
    [InlineData("unrecognized failure", "mpv_end_file_error")]
    public async Task EndFileErrorsUseOnlySanitizedClassification(string fileError, string expected)
    {
        var process = new FakeMpvProcess();
        await using var player = Player(() => process);
        await player.StartAsync(Channel(1), default);
        process.Emit("""{"event":"start-file","playlist_entry_id":1}""");
        process.Emit(JsonSerializer.Serialize(new
        {
            @event = "end-file",
            playlist_entry_id = 1,
            reason = "error",
            file_error = fileError,
        }));

        Assert.Equal(expected, player.Snapshot().LastErrorCode);
        Assert.DoesNotContain("private", player.Snapshot().LastErrorCode, StringComparison.OrdinalIgnoreCase);
    }

    [Fact]
    public async Task AudioOnlyPlaybackNeverShowsNativeVideoHost()
    {
        var process = new FakeMpvProcess();
        var visibility = new List<bool>();
        await using var player = Player(() => process);
        player.VisibilityChanged += (_, value) => visibility.Add(value);

        await player.StartAsync(Channel(1), default);
        process.Emit("""{"event":"start-file","playlist_entry_id":1}""");
        process.Emit("""{"event":"property-change","name":"vid","data":"no"}""");
        process.Emit("""{"event":"property-change","name":"audio-codec-name","data":"aac"}""");
        process.Emit("""{"event":"playback-restart","playlist_entry_id":1}""");

        Assert.Equal("playing", player.Snapshot().State);
        Assert.DoesNotContain(true, visibility);
    }

    [Fact]
    public void StartupArgumentsRemainMinimalAndExcludeProviderUrl()
    {
        Assert.Equal(new[]
        {
            "--no-config", "--idle=yes", "--force-window=yes", "--osc=no",
            "--input-default-bindings=no", "--terminal=no", "--msg-level=all=error",
            "--hwdec=auto", "--vo=gpu-next", "--gpu-api=d3d11", "--keep-open=yes",
            "--cache=yes", "--demuxer-max-bytes=50MiB", "--network-timeout=10",
            "--wid=123", "--input-ipc-server=\\\\.\\pipe\\test-pipe",
        }, MpvProcess.BuildArguments((nint)123, "test-pipe"));
    }

    [Theory]
    [InlineData(0, 0, 800, 450, 1200, 800, true)]
    [InlineData(-1, 0, 800, 450, 1200, 800, false)]
    [InlineData(500, 0, 800, 450, 1200, 800, false)]
    [InlineData(0, 0, 0, 450, 1200, 800, false)]
    public void GeometryRejectsCoordinatesOutsideViewport(double x, double y, double width, double height, double viewportWidth, double viewportHeight, bool expected)
    {
        Assert.Equal(expected, new MpvGeometry(x, y, width, height, viewportWidth, viewportHeight).IsValid);
    }

    [Fact]
    public void HiddenGeometryUsesZeroSizedOffscreenContract()
    {
        Assert.True(new MpvGeometry(0, 0, 0, 0, 1200, 800, false).IsValid);
        Assert.Null(NativeVideoLayout.Calculate(new MpvGeometry(0, 0, 0, 0, 1200, 800, false), 1200, 800));
    }

    [Fact]
    public void NativeLayoutMapsCssViewportAndPhysicalDpiWithoutZeroGeometry()
    {
        var geometry = new MpvGeometry(100, 50, 800, 450, 1200, 800);
        var layout = NativeVideoLayout.Calculate(geometry, 1500, 1000);

        Assert.NotNull(layout);
        Assert.Equal(new NativeVideoLayout(125, 62.5, 1000, 562.5), layout);
        Assert.Equal((1250, 703), layout.PhysicalSize(1.25, 1.25));
        Assert.Equal((1500, 844), layout.PhysicalSize(1.5, 1.5));
        Assert.Null(NativeVideoLayout.Calculate(null, 1500, 1000));
        Assert.Null(NativeVideoLayout.Calculate(geometry, 0, 1000));
    }

    [Fact]
    public void NativeZOrderPolicyPromotesOnlyInsideParentWithoutTopmostOrActivation()
    {
        const uint swpNoActivate = 0x0010;
        const uint swpShowWindow = 0x0040;
        Assert.Equal(0, MpvVideoHost.PlaybackInsertAfter);
        Assert.NotEqual(0U, MpvVideoHost.PlaybackZOrderFlags & swpNoActivate);
        Assert.NotEqual(0U, MpvVideoHost.PlaybackZOrderFlags & swpShowWindow);
    }

    [Fact]
    public void CursorMonitorDetectsOnlyRealMovement()
    {
        var source = new FakeCursorActivitySource(new(500, 500));
        using var monitor = new CursorActivityMonitor(source, scheduleAutomatically: false);
        var activities = 0;
        monitor.Activity += (_, _) => activities++;

        monitor.Start((nint)42);
        monitor.PollNow();
        Assert.Equal(0, activities);
        source.Position = new(501, 500);
        monitor.PollNow();
        Assert.Equal(1, activities);
        monitor.PollNow();
        Assert.Equal(1, activities);
        Assert.Equal(100, CursorActivityMonitor.PollIntervalMilliseconds);
    }

    [Fact]
    public void CursorMonitorRequiresForegroundWindowAndWindowBounds()
    {
        var source = new FakeCursorActivitySource(new(500, 500));
        using var monitor = new CursorActivityMonitor(source, scheduleAutomatically: false);
        var activities = 0;
        monitor.Activity += (_, _) => activities++;
        monitor.Start((nint)42);

        source.Foreground = false;
        source.Position = new(501, 500);
        monitor.PollNow();
        source.Foreground = true;
        source.InsideWindow = false;
        source.Position = new(502, 500);
        monitor.PollNow();
        Assert.Equal(0, activities);

        source.InsideWindow = true;
        source.Position = new(503, 500);
        monitor.PollNow();
        Assert.Equal(1, activities);
    }

    [Fact]
    public async Task CursorMonitorStopDrainsInFlightActivityAndPreventsLateCallbacks()
    {
        var source = new FakeCursorActivitySource(new(500, 500));
        using var monitor = new CursorActivityMonitor(source, scheduleAutomatically: false);
        using var entered = new ManualResetEventSlim();
        using var release = new ManualResetEventSlim();
        var activities = 0;
        monitor.Activity += (_, _) =>
        {
            Interlocked.Increment(ref activities);
            entered.Set();
            Assert.True(release.Wait(TimeSpan.FromSeconds(5)));
        };
        monitor.Start((nint)42);
        source.Position = new(501, 500);

        var poll = Task.Run(monitor.PollNow);
        Assert.True(entered.Wait(TimeSpan.FromSeconds(5)));
        var stop = Task.Run(monitor.Stop);
        await Task.Delay(30);
        Assert.False(stop.IsCompleted);
        release.Set();
        await Task.WhenAll(poll, stop);

        source.Position = new(502, 500);
        monitor.PollNow();
        Assert.Equal(1, activities);
        Assert.False(monitor.IsRunning);
    }

    [Fact]
    public void CursorMonitorLifecycleRequiresHiddenFullscreenVisiblePlayback()
    {
        var source = new FakeCursorActivitySource(new(500, 500));
        using var monitor = new CursorActivityMonitor(source, scheduleAutomatically: false);
        using var controller = new PlayerCursorActivityController(monitor, () => (nint)42);

        controller.SetPlaybackVisible(true);
        controller.SetFullscreen(true);
        Assert.False(controller.IsRunning);
        controller.SetControlsHidden(true);
        Assert.True(controller.IsRunning);
        controller.SetControlsHidden(false);
        Assert.False(controller.IsRunning);
        controller.SetControlsHidden(true);
        Assert.True(controller.IsRunning);
        controller.SetFullscreen(false);
        Assert.False(controller.IsRunning);
        controller.SetFullscreen(true);
        Assert.True(controller.IsRunning);
        controller.SetPlaybackVisible(false);
        Assert.False(controller.IsRunning);
        controller.SetPlaybackVisible(true);
        Assert.True(controller.IsRunning);
        controller.Reset();
        Assert.False(controller.IsRunning);
        controller.Dispose();
        controller.SetPlaybackVisible(true);
        controller.SetFullscreen(true);
        controller.SetControlsHidden(true);
        Assert.False(controller.IsRunning);
    }

    [Fact]
    public void ControlsVisibilityMessageIsStrictAndUnilateral()
    {
        Assert.True(DesktopMessage.TryReadPlayerControlsVisibility(
            "{\"type\":\"semyra.desktop.player.controls-visibility\",\"hidden\":true}", out var hidden));
        Assert.True(hidden);
        Assert.False(DesktopMessage.TryReadPlayerControlsVisibility(
            "{\"type\":\"semyra.desktop.player.controls-visibility\",\"hidden\":true,\"requestId\":\"bad\"}", out _));
        Assert.False(DesktopMessage.TryReadPlayerControlsVisibility(
            "{\"type\":\"semyra.desktop.player.controls-visibility\",\"hidden\":\"true\"}", out _));
    }

    [Fact]
    public void CursorMovementCompletesNativeActivityBridgeCycle()
    {
        var source = new FakeCursorActivitySource(new(500, 500));
        using var monitor = new CursorActivityMonitor(source, scheduleAutomatically: false);
        using var controller = new PlayerCursorActivityController(monitor, () => (nint)42);
        var messages = new List<string>();
        controller.Activity += (_, _) => MainWindow.ForwardPlayerUserActivity(messages.Add);

        controller.SetPlaybackVisible(true);
        controller.SetFullscreen(true);
        controller.SetControlsHidden(true);
        Assert.True(controller.IsRunning);
        source.Position = new(501, 500);
        monitor.PollNow();

        var message = Assert.Single(messages);
        using var json = JsonDocument.Parse(message);
        Assert.Equal(DesktopBridge.PlayerUserActivityType, json.RootElement.GetProperty("type").GetString());
        controller.SetControlsHidden(false);
        Assert.False(controller.IsRunning);
    }

    [Fact]
    public void MainWindowForwardsMinimalSafePlayerActivityMessage()
    {
        var messages = new List<string>();

        MainWindow.ForwardPlayerUserActivity(messages.Add);

        var message = Assert.Single(messages);
        using var json = JsonDocument.Parse(message);
        Assert.Equal(DesktopBridge.PlayerUserActivityType, json.RootElement.GetProperty("type").GetString());
        Assert.Single(json.RootElement.EnumerateObject());
        Assert.DoesNotContain("channel", message, StringComparison.OrdinalIgnoreCase);
        Assert.DoesNotContain("token", message, StringComparison.OrdinalIgnoreCase);
        Assert.DoesNotContain("url", message, StringComparison.OrdinalIgnoreCase);
    }

    private static MpvPlayer Player(Func<FakeMpvProcess> factory) =>
        new(new MpvRuntime(true, "mpv.exe", "mpv test", "test", null), (nint)123, (_, _) => factory());

    private static IptvPlaybackChannel Channel(long id) =>
        new(id, 1, "Video " + id, "Movies", new Uri($"https://example.invalid/private/{id}.mp4"));

    private sealed class FakeCursorActivitySource(CursorScreenPoint initial) : ICursorActivitySource
    {
        internal CursorScreenPoint Position { get; set; } = initial;
        internal bool Foreground { get; set; } = true;
        internal bool InsideWindow { get; set; } = true;

        public bool TryGetPosition(out CursorScreenPoint position)
        {
            position = Position;
            return true;
        }

        public bool IsForeground(nint windowHandle) => Foreground && windowHandle == (nint)42;
        public bool IsInsideWindow(nint windowHandle, CursorScreenPoint position) => InsideWindow && windowHandle == (nint)42;
    }

    private sealed class FakeMpvProcess : IMpvProcess
    {
        public event EventHandler<JsonElement>? MessageReceived;
        public event EventHandler? Exited;
        public bool IsRunning { get; private set; }
        public int? ProcessId => IsRunning ? 42 : null;
        public long? WorkingSetBytes => 12_345_678;
        public string PipeName { get; } = "semyra-mpv-test";
        public IReadOnlyList<string> Arguments { get; } = ["--idle=yes"];
        public List<(string Command, IReadOnlyList<object?> Arguments)> Commands { get; } = [];
        public bool Disposed { get; private set; }
        public int StartCount { get; private set; }
        public Action? Starting { get; set; }
        public double InitialVolume { get; set; } = 100;
        public bool InitialMuted { get; set; }
        public bool AutoEmitPropertyChanges { get; set; } = true;
        private bool Paused { get; set; }
        private bool Muted { get; set; }
        private double Volume { get; set; } = 100;
        private bool PausedForCache { get; set; }
        private double Position { get; set; }
        private double Duration { get; set; }

        public Task StartAsync(CancellationToken cancellationToken)
        {
            Starting?.Invoke();
            StartCount++;
            IsRunning = true;
            Muted = InitialMuted;
            Volume = InitialVolume;
            return Task.CompletedTask;
        }

        public Task<JsonElement> CommandAsync(string command, IReadOnlyList<object?> arguments, CancellationToken cancellationToken)
        {
            Commands.Add((command, arguments));
            if (command == "get_property" && Equals(arguments[0], "pause"))
                return Task.FromResult(JsonSerializer.SerializeToElement(new { data = Paused }));
            if (command == "get_property" && Equals(arguments[0], "volume"))
                return Task.FromResult(JsonSerializer.SerializeToElement(new { data = Volume }));
            if (command == "get_property" && Equals(arguments[0], "mute"))
                return Task.FromResult(JsonSerializer.SerializeToElement(new { data = Muted }));
            if (command == "get_property" && Equals(arguments[0], "paused-for-cache"))
                return Task.FromResult(JsonSerializer.SerializeToElement(new { data = PausedForCache }));
            if (command == "get_property" && Equals(arguments[0], "time-pos"))
                return Task.FromResult(JsonSerializer.SerializeToElement(new { data = Position }));
            if (command == "get_property" && Equals(arguments[0], "duration"))
                return Task.FromResult(JsonSerializer.SerializeToElement(new { data = Duration }));
            if (command == "set_property" && arguments.Count == 2 && arguments[0] is string property
                && property is "pause" or "mute" or "volume")
            {
                if (property == "pause") Paused = Assert.IsType<bool>(arguments[1]);
                if (property == "mute") Muted = Assert.IsType<bool>(arguments[1]);
                if (property == "volume") Volume = Convert.ToDouble(arguments[1]);
                if (AutoEmitPropertyChanges)
                    Emit(JsonSerializer.Serialize(new { @event = "property-change", name = property, data = arguments[1] }));
            }
            return Task.FromResult(JsonDocument.Parse("{}").RootElement.Clone());
        }

        public void Emit(string json)
        {
            using var document = JsonDocument.Parse(json);
            var root = document.RootElement;
            if (root.TryGetProperty("event", out var eventName)
                && eventName.GetString() == "property-change"
                && root.TryGetProperty("name", out var propertyName)
                && root.TryGetProperty("data", out var data))
            {
                switch (propertyName.GetString())
                {
                    case "pause" when data.ValueKind is JsonValueKind.True or JsonValueKind.False: Paused = data.GetBoolean(); break;
                    case "mute" when data.ValueKind is JsonValueKind.True or JsonValueKind.False: Muted = data.GetBoolean(); break;
                    case "paused-for-cache" when data.ValueKind is JsonValueKind.True or JsonValueKind.False: PausedForCache = data.GetBoolean(); break;
                    case "volume" when data.ValueKind == JsonValueKind.Number && data.TryGetDouble(out var volume): Volume = volume; break;
                    case "time-pos" when data.ValueKind == JsonValueKind.Number && data.TryGetDouble(out var position): Position = position; break;
                    case "duration" when data.ValueKind == JsonValueKind.Number && data.TryGetDouble(out var duration): Duration = duration; break;
                }
            }
            MessageReceived?.Invoke(this, document.RootElement.Clone());
        }

        public void Exit()
        {
            IsRunning = false;
            Exited?.Invoke(this, EventArgs.Empty);
        }

        public ValueTask DisposeAsync()
        {
            Disposed = true;
            IsRunning = false;
            return ValueTask.CompletedTask;
        }
    }
}
