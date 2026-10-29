using Semyra.Desktop.Host;
using Semyra.Desktop.Iptv;
using Semyra.Desktop.Media;
using Semyra.Desktop.Desktop;

namespace Semyra.Desktop.Tests;

public sealed class MediaEngineTests
{
    [Fact]
    public async Task MpegTsValidationAcceptsAlignedPacketsAndRejectsOtherBodies()
    {
        var valid = ValidMpegTs(prefix: 7);
        var initial = await MpegTsValidator.ReadAndValidateAsync(new MemoryStream(valid), default);
        Assert.Equal(valid, initial);

        var exception = await Assert.ThrowsAsync<MediaEngineException>(() =>
            MpegTsValidator.ReadAndValidateAsync(new MemoryStream(new byte[900]), default));
        Assert.Equal("invalid_mpegts", exception.Code);
    }

    [Fact]
    public void PublishPipelineUsesWhipEndpointOnlyInArgumentList()
    {
        var endpoint = new Uri("https://whip.example/ephemeral");
        var process = new GStreamerProcess("gst-launch-1.0.exe", MediaPipeline.PublishArguments(endpoint));
        var startInfo = process.CreateStartInfo();
        var arguments = startInfo.ArgumentList.ToArray();

        Assert.Contains("whipsink", arguments);
        Assert.Contains("whip.sink_0", arguments);
        Assert.Contains("whip.sink_1", arguments);
        Assert.Contains("whip-endpoint=" + endpoint.AbsoluteUri, arguments);
        Assert.DoesNotContain(arguments, value => value.Contains("provider.example", StringComparison.Ordinal));
        Assert.DoesNotContain(startInfo.Environment, pair => pair.Value?.Contains(endpoint.AbsoluteUri, StringComparison.Ordinal) == true);
    }

    [Fact]
    public void LivePipelineAlwaysNormalizesVideoForWebRtcWithoutVodClockSync()
    {
        var endpoint = new Uri("https://whip.example/ephemeral");
        var arguments = MediaPipeline.PublishArguments(endpoint);

        Assert.Contains("avdec_h264", arguments);
        Assert.Contains("videoconvert", arguments);
        Assert.Contains("x264enc", arguments);
        Assert.Contains("bitrate=3500", arguments);
        Assert.Contains("tune=zerolatency", arguments);
        Assert.Contains("bframes=0", arguments);
        Assert.Contains("key-int-max=48", arguments);
        Assert.Contains("cabac=false", arguments);
        Assert.Contains("video/x-h264,stream-format=byte-stream,alignment=au,profile=constrained-baseline", arguments);
        Assert.Contains("aggregate-mode=zero-latency", arguments);
        Assert.DoesNotContain("clocksync", arguments);
    }

    [Fact]
    public void DiagnosticLiveModeIsAllowlistedAndPassthroughRemainsExplicit()
    {
        Assert.Equal(LivePublishVideoMode.Transcode, LivePublishVideoModeResolver.Parse(null));
        Assert.Equal(LivePublishVideoMode.Transcode, LivePublishVideoModeResolver.Parse("transcode"));
        Assert.Equal(LivePublishVideoMode.Passthrough, LivePublishVideoModeResolver.Parse("passthrough"));
        Assert.Equal(LivePublishVideoMode.Transcode, LivePublishVideoModeResolver.Parse("arbitrary"));

        var passthrough = MediaPipeline.LivePublishPassthroughArguments(new Uri("https://whip.example/ephemeral"));
        Assert.DoesNotContain("x264enc", passthrough);
        Assert.DoesNotContain("avdec_h264", passthrough);
        Assert.Contains("h264parse", passthrough);
        Assert.Contains("rtph264pay", passthrough);
    }

    [Fact]
    public void VodPipelineUsesOnlyOpaqueLoopbackSourceAndWebRtcCompatibleTranscode()
    {
        var local = new Uri("http://127.0.0.1:49152/" + new string('a', 64) + "/media");
        var endpoint = new Uri("https://whip.example/ephemeral");
        var arguments = MediaPipeline.VodPublishArguments(local, endpoint);

        Assert.Contains("souphttpsrc", arguments);
        Assert.Contains("qtdemux", arguments);
        Assert.Contains("h264parse", arguments);
        Assert.Contains("avdec_h264", arguments);
        Assert.Contains("videoconvert", arguments);
        Assert.Contains("x264enc", arguments);
        Assert.Contains("rtph264pay", arguments);
        Assert.Contains("aacparse", arguments);
        Assert.Contains("opusenc", arguments);
        Assert.Equal(2, arguments.Count(value => value == "clocksync"));
        Assert.Contains("whipsink", arguments);
        Assert.Contains("location=" + local.AbsoluteUri, arguments);
        Assert.DoesNotContain(arguments, value => value.Contains("provider.example", StringComparison.OrdinalIgnoreCase));
        Assert.Contains("tune=zerolatency", arguments);
        Assert.Contains("bframes=0", arguments);
        Assert.Contains("key-int-max=48", arguments);
        Assert.Contains("bitrate=2800", arguments);
        Assert.Contains("cabac=false", arguments);
        Assert.Contains("video/x-h264,stream-format=byte-stream,alignment=au,profile=constrained-baseline", arguments);
        Assert.True(IndexAfter(arguments, "clocksync", "x264enc") < IndexAfter(arguments, "rtph264pay", "clocksync"));
        var pipeline = arguments.ToArray();
        var opus = Array.IndexOf(pipeline, "opusenc");
        var audioClock = Array.IndexOf(pipeline, "clocksync", opus + 1);
        Assert.True(opus < audioClock && audioClock < Array.IndexOf(pipeline, "rtpopuspay", audioClock + 1));
        Assert.DoesNotContain("clocksync", MediaPipeline.PublishArguments(endpoint));
        Assert.Contains("x264enc", MediaPipeline.PublishArguments(endpoint));
    }

    [Fact]
    public void DiagnosticVodModeIsAllowlistedAndPassthroughRemainsExplicit()
    {
        Assert.Equal(VodPublishVideoMode.Transcode, VodPublishVideoModeResolver.Parse(null));
        Assert.Equal(VodPublishVideoMode.Transcode, VodPublishVideoModeResolver.Parse("transcode"));
        Assert.Equal(VodPublishVideoMode.Passthrough, VodPublishVideoModeResolver.Parse("passthrough"));
        Assert.Equal(VodPublishVideoMode.Transcode, VodPublishVideoModeResolver.Parse("arbitrary"));

        var local = new Uri("http://127.0.0.1:49152/" + new string('a', 64) + "/media");
        var endpoint = new Uri("https://whip.example/ephemeral");
        var passthrough = MediaPipeline.VodPublishPassthroughArguments(local, endpoint);
        Assert.DoesNotContain("x264enc", passthrough);
        Assert.Contains("h264parse", passthrough);
        Assert.Contains("rtph264pay", passthrough);
        Assert.Contains("location=" + local.AbsoluteUri, passthrough);
        Assert.DoesNotContain(passthrough, value => value.Contains("provider.example", StringComparison.OrdinalIgnoreCase));
    }

    private static int IndexAfter(IReadOnlyList<string> arguments, string value, string after)
    {
        var values = arguments.ToArray();
        var start = Array.IndexOf(values, after);
        return Array.IndexOf(values, value, start + 1);
    }

    [Fact]
    public async Task PublishModeProvisionsAndCleansIngressWhileRenewedAuthorizationIsReadFromMemory()
    {
        var provider = new FakeProvider();
        var pipelines = new FakePipelineFactory();
        var publish = new FakePublishClient();
        var authorization = Authorization("a");
        using var engine = new MediaEngine(Resolve, GStreamerRuntime.AvailableForTests(), provider, pipelines,
            publishClient: publish);

        var snapshot = await engine.StartPublishAsync(10, () => authorization);
        authorization = Authorization("b");
        await engine.StopAsync();

        Assert.Equal("publish", snapshot.Mode);
        Assert.Single(publish.Starts);
        Assert.Single(publish.Stops);
        Assert.EndsWith(new string('b', 64), publish.Stops[0].Token);
    }

    [Fact]
    public async Task HostClearStopsPublishBeforeDroppingAuthorization()
    {
        var publish = new FakePublishClient();
        var media = new MediaEngine(Resolve, GStreamerRuntime.AvailableForTests(), new FakeProvider(), new FakePipelineFactory(),
            publishClient: publish);
        using var host = new HostEngine(media: media);
        host.Start();
        Assert.True(host.Authorize(Authorization("a")));
        await host.StartIptvPublishAsync(10, default);

        await host.ClearAuthorizationAsync();

        Assert.Equal("idle", host.IptvMediaSnapshot().State);
        Assert.False(host.Snapshot().Authorization.Authorized);
        Assert.Single(publish.Stops);
    }

    [Fact]
    public async Task ScheduledRoomLeaveStopsNativePublisherAfterGrace()
    {
        var publish = new FakePublishClient();
        var media = new MediaEngine(
            Resolve,
            GStreamerRuntime.AvailableForTests(),
            new FakeProvider(),
            new FakePipelineFactory(),
            publishClient: publish);
        await using var host = new HostEngine(media);
        host.Start();
        Assert.True(host.Authorize(Authorization("a")));
        await host.StartIptvPublishAsync(10, default);

        host.ScheduleAuthorizationClear(TimeSpan.FromMilliseconds(20));
        for (var index = 0; index < 50
            && (host.IptvMediaSnapshot().State != "idle" || host.Snapshot().Authorization.Authorized);
            index++)
        {
            await Task.Delay(10);
        }

        Assert.Equal("idle", host.IptvMediaSnapshot().State);
        Assert.False(host.Snapshot().Authorization.Authorized);
        Assert.Single(publish.Stops);
    }

    [Fact]
    public async Task HostClearInvalidatesAuthorizationWithoutBlockingOnPendingMediaStop()
    {
        var stopGate = new TaskCompletionSource<bool>(TaskCreationOptions.RunContinuationsAsynchronously);
        var pipelines = new FakePipelineFactory(stopGate: stopGate);
        var media = new MediaEngine(
            Resolve,
            GStreamerRuntime.AvailableForTests(),
            new FakeProvider(),
            pipelines,
            publishClient: new FakePublishClient());
        await using var host = new HostEngine(media);
        host.Start();
        Assert.True(host.Authorize(Authorization("a")));
        await host.StartIptvPublishAsync(10, default);

        var clear = host.ClearAuthorizationAsync();

        Assert.False(clear.IsCompleted);
        Assert.False(host.Snapshot().Authorization.Authorized);
        await pipelines.Pipelines[0].StopStarted.Task.WaitAsync(TimeSpan.FromSeconds(2));
        stopGate.SetResult(true);
        await clear;
        Assert.Equal("idle", host.IptvMediaSnapshot().State);
    }

    [Fact]
    public async Task StartStreamsOnceForSameChannelAndStopIsIdempotent()
    {
        var provider = new FakeProvider();
        var pipelines = new FakePipelineFactory();
        using var engine = Engine(provider, pipelines);
        var events = new List<MediaSnapshot>();
        engine.StateChanged += (_, snapshot) => events.Add(snapshot);

        var first = await engine.StartPublishAsync(10, () => Authorization("a"));
        var duplicate = await engine.StartPublishAsync(10, () => Authorization("a"));
        var stopped = await engine.StopAsync();
        var stoppedAgain = await engine.StopAsync();

        Assert.Equal("streaming", first.State);
        Assert.Equal("streaming", duplicate.State);
        Assert.Equal("idle", stopped.State);
        Assert.Equal("idle", stoppedAgain.State);
        Assert.Equal(1, provider.OpenCount);
        Assert.Equal(1, pipelines.CreateCount);
        Assert.Contains(events, snapshot => snapshot.State == "preparing");
        Assert.Contains(events, snapshot => snapshot.State == "streaming");
    }

    [Fact]
    public async Task StreamingIsPublishedOnlyAfterPipelineReadiness()
    {
        var startGate = new TaskCompletionSource<bool>(TaskCreationOptions.RunContinuationsAsynchronously);
        var pipelines = new FakePipelineFactory(startGate: startGate);
        using var engine = Engine(new FakeProvider(), pipelines);

        var start = engine.StartPublishAsync(10, () => Authorization("a"));
        await Task.Delay(30);
        Assert.Equal("preparing", engine.Snapshot().State);
        Assert.False(start.IsCompleted);

        startGate.SetResult(true);
        Assert.Equal("streaming", (await start).State);
    }

    [Fact]
    public async Task ChannelReplacementCancelsPreviousSession()
    {
        var provider = new FakeProvider();
        var pipelines = new FakePipelineFactory();
        using var engine = Engine(provider, pipelines);

        await engine.StartPublishAsync(10, () => Authorization("a"));
        var replacement = await engine.StartPublishAsync(20, () => Authorization("a"));

        Assert.Equal(20, replacement.ChannelId);
        Assert.Equal(2, provider.OpenCount);
        Assert.Equal(2, pipelines.CreateCount);
        Assert.True(pipelines.Pipelines[0].Stopped);
    }

    [Fact]
    public async Task FailureBudgetUsesOneTwoFiveSecondBackoffThenFails()
    {
        var delays = new List<TimeSpan>();
        var pipelines = new FakePipelineFactory(failedStarts: 4);
        using var engine = Engine(
            new FakeProvider(),
            pipelines,
            (delay, _) => { delays.Add(delay); return Task.CompletedTask; });
        var states = new List<string>();
        engine.StateChanged += (_, snapshot) => states.Add(snapshot.State);

        var result = await engine.StartPublishAsync(10, () => Authorization("a"));

        Assert.Equal("failed", result.State);
        Assert.Equal("pipeline_start_failed", result.LastErrorCode);
        Assert.Equal(4, result.Attempt);
        Assert.Equal([TimeSpan.FromSeconds(1), TimeSpan.FromSeconds(2), TimeSpan.FromSeconds(5)], delays);
        Assert.Contains("reconnecting", states);
    }

    [Fact]
    public async Task UnexpectedPipelineExitTransitionsThroughReconnectAndFailureBudget()
    {
        var pipelines = new FakePipelineFactory(failedPumps: 4);
        using var engine = Engine(
            new FakeProvider(),
            pipelines,
            (_, _) => Task.CompletedTask);
        var states = new List<string>();
        engine.StateChanged += (_, snapshot) => states.Add(snapshot.State);

        Assert.Equal("streaming", (await engine.StartPublishAsync(10, () => Authorization("a"))).State);
        for (var index = 0; index < 50 && engine.Snapshot().State != "failed"; index++)
        {
            await Task.Delay(10);
        }

        Assert.Equal("failed", engine.Snapshot().State);
        Assert.Equal("pipeline_exited", engine.Snapshot().LastErrorCode);
        Assert.Contains("reconnecting", states);
        Assert.Equal(4, pipelines.CreateCount);
    }

    [Fact]
    public async Task ManualStopDoesNotReconnectAndHostStopStopsMediaFirst()
    {
        var provider = new FakeProvider();
        var pipelines = new FakePipelineFactory();
        var media = Engine(provider, pipelines);
        using var host = new HostEngine(media: media);
        host.Start();
        Assert.True(host.Authorize(Authorization("a")));
        await host.StartIptvPublishAsync(10, default);

        await host.StopAsync();
        await Task.Delay(20);

        Assert.Equal(HostEngineState.Stopped, host.State);
        Assert.Equal("idle", host.IptvMediaSnapshot().State);
        Assert.Equal(1, pipelines.CreateCount);
        Assert.True(pipelines.Pipelines[0].Stopped);
    }

    [Fact]
    public void MediaSnapshotsNeverContainProviderUrl()
    {
        using var engine = Engine(new FakeProvider(), new FakePipelineFactory());
        var json = System.Text.Json.JsonSerializer.Serialize(engine.Snapshot());
        Assert.DoesNotContain("provider.example", json);
        Assert.DoesNotContain("stream", json, StringComparison.OrdinalIgnoreCase);
    }

    private static MediaEngine Engine(
        FakeProvider provider,
        FakePipelineFactory pipelines,
        Func<TimeSpan, CancellationToken, Task>? delay = null)
    {
        return new MediaEngine(
            Resolve,
            GStreamerRuntime.AvailableForTests(),
            provider,
            pipelines,
            delay,
            new FakePublishClient());
    }

    private static IptvPlaybackChannel Resolve(long channelId)
    {
        if (channelId <= 0)
        {
            throw new IptvPlaybackException("channel_not_found");
        }
        return new IptvPlaybackChannel(
            channelId,
            1,
            "Canal seguro " + channelId,
            "Live",
            new Uri("https://provider.example/private/live.ts"));
    }

    private static byte[] ValidMpegTs(int prefix = 0)
    {
        var bytes = new byte[prefix + (188 * 4)];
        for (var index = 0; index < 4; index++)
        {
            bytes[prefix + (index * 188)] = 0x47;
        }
        return bytes;
    }

    private static HostAuthorization Authorization(string validator) => new(
        new string('a', 32) + "." + new string(validator[0], 64), "ROOM2345", new string('c', 32), 2,
        "media.publish", DateTimeOffset.UtcNow.AddMinutes(5));

    private sealed class FakeProvider : IProviderStreamClient
    {
        public int OpenCount { get; private set; }
        public Task<Stream> OpenAsync(Uri streamUri, CancellationToken cancellationToken)
        {
            OpenCount++;
            return Task.FromResult<Stream>(new MemoryStream(ValidMpegTs(), writable: false));
        }
        public void Dispose() { }
    }

    private sealed class FakePipelineFactory(
        int failedStarts = 0,
        int failedPumps = 0,
        TaskCompletionSource<bool>? startGate = null,
        TaskCompletionSource<bool>? pumpFailureGate = null,
        TaskCompletionSource<bool>? stopGate = null) : IMediaPipelineFactory
    {
        public List<FakePipeline> Pipelines { get; } = [];
        public int CreateCount => Pipelines.Count;
        public IMediaPipeline CreatePublish(Uri whipEndpoint)
        {
            var pipeline = new FakePipeline(
                Pipelines.Count < failedStarts,
                Pipelines.Count < failedPumps,
                startGate,
                Pipelines.Count == 0 ? pumpFailureGate : null,
                stopGate);
            Pipelines.Add(pipeline);
            return pipeline;
        }
    }

    private sealed class FakePublishClient : IDesktopPublishClient
    {
        public Task<DesktopPublishStatus> StatusAsync(HostAuthorization authorization, string ingressId, CancellationToken cancellationToken) =>
            Task.FromResult(new DesktopPublishStatus("publishing"));
        public List<HostAuthorization> Starts { get; } = [];
        public List<HostAuthorization> Stops { get; } = [];
        public Task<DesktopPublishLease> StartAsync(HostAuthorization authorization, CancellationToken cancellationToken)
        {
            Starts.Add(authorization);
            return Task.FromResult(new DesktopPublishLease("INGRESS_TEST", new Uri("https://whip.example/test")));
        }
        public Task StopAsync(HostAuthorization authorization, string ingressId, CancellationToken cancellationToken)
        {
            Stops.Add(authorization);
            return Task.CompletedTask;
        }
    }

    private sealed class FakePipeline(
        bool failStart,
        bool failPump,
        TaskCompletionSource<bool>? startGate,
        TaskCompletionSource<bool>? pumpFailureGate,
        TaskCompletionSource<bool>? stopGate) : IMediaPipeline
    {
        public bool Stopped { get; private set; }
        public TaskCompletionSource<bool> StopStarted { get; } = new(TaskCreationOptions.RunContinuationsAsynchronously);
        public async Task StartAsync(ReadOnlyMemory<byte> initialBuffer, CancellationToken cancellationToken)
        {
            Assert.True(initialBuffer.Length >= 188 * 3);
            if (failStart) throw new MediaEngineException("pipeline_start_failed");
            if (startGate is not null) await startGate.Task.WaitAsync(cancellationToken);
        }
        public Task PumpAsync(Stream providerStream, CancellationToken cancellationToken)
        {
            if (failPump) throw new MediaEngineException("pipeline_exited");
            if (pumpFailureGate is not null) return FailAfterGateAsync(pumpFailureGate, cancellationToken);
            return Task.Delay(Timeout.InfiniteTimeSpan, cancellationToken);
        }
        private static async Task FailAfterGateAsync(TaskCompletionSource<bool> gate, CancellationToken cancellationToken)
        {
            await gate.Task.WaitAsync(cancellationToken);
            throw new MediaEngineException("pipeline_exited");
        }
        public async Task StopAsync()
        {
            Stopped = true;
            StopStarted.TrySetResult(true);
            if (stopGate is not null)
            {
                await stopGate.Task;
            }
        }
        public async ValueTask DisposeAsync() { await StopAsync(); }
    }

    private sealed class FakeCommandRunner : IGStreamerCommandRunner
    {
        public (int ExitCode, string Output) Run(string executable, IReadOnlyList<string> arguments) => (1, string.Empty);
    }
}
