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
    public void PipelineUsesArgumentListWithoutProviderUrlOrShell()
    {
        const string providerUrl = "https://provider.example/user/password/live.ts";
        var process = new GStreamerProcess("gst-launch-1.0.exe", MediaPipeline.Arguments);
        var startInfo = process.CreateStartInfo();
        var arguments = startInfo.ArgumentList.ToArray();

        Assert.False(startInfo.UseShellExecute);
        Assert.True(startInfo.RedirectStandardInput);
        Assert.True(startInfo.RedirectStandardError);
        Assert.True(startInfo.CreateNoWindow);
        Assert.DoesNotContain(arguments, value => value.Contains(providerUrl, StringComparison.Ordinal));
        Assert.DoesNotContain(startInfo.Environment, pair => pair.Value?.Contains(providerUrl, StringComparison.Ordinal) == true);
        Assert.Contains("h264parse", arguments);
        Assert.Contains("rtph264pay", arguments);
        Assert.Contains("aacparse", arguments);
        Assert.Contains("avdec_aac", arguments);
        Assert.Contains("opusenc", arguments);
        Assert.Equal(2, arguments.Count(value => value == "fakesink"));
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
    public void LocalViewPipelineWritesOnlyEphemeralHlsFilesInItsWorkingDirectory()
    {
        var directory = Path.Combine(Path.GetTempPath(), "semyra-hls-test");
        var process = new GStreamerProcess("gst-launch-1.0.exe", MediaPipeline.LocalViewArguments, directory);
        var startInfo = process.CreateStartInfo();
        var arguments = startInfo.ArgumentList.ToArray();

        Assert.Equal(directory, startInfo.WorkingDirectory);
        Assert.Contains("mpegtsmux", arguments);
        Assert.Contains("hlssink", arguments);
        Assert.Contains("playlist-location=index.m3u8", arguments);
        Assert.Contains("location=segment%05d.ts", arguments);
        Assert.DoesNotContain(arguments, value => value.Contains("provider.example", StringComparison.Ordinal));
    }

    [Fact]
    public async Task LocalViewReturnsOpaquePlaybackUrlAndStopInvalidatesIt()
    {
        var pipelines = new FakePipelineFactory(writePlaylist: true);
        using var engine = Engine(new FakeProvider(), pipelines);

        var started = await engine.StartLocalViewAsync(10);
        var duplicate = await engine.StartLocalViewAsync(10);
        var uri = new Uri(new Uri("https://semyra.example/"), started.PlaybackUrl);

        Assert.Equal("view", started.Snapshot.Mode);
        Assert.Matches("^/__desktop/playback/[a-f0-9]{64}/index\\.m3u8$", started.PlaybackUrl);
        Assert.Equal(started.PlaybackUrl, duplicate.PlaybackUrl);
        Assert.Equal(1, pipelines.CreateCount);
        Assert.True(engine.TryReadLocalPlayback(uri, "GET", out var resource));
        Assert.Equal("application/vnd.apple.mpegurl", resource!.ContentType);

        await engine.StopAsync();
        Assert.False(engine.TryReadLocalPlayback(uri, "GET", out _));
    }

    [Fact]
    public async Task StopInvalidatesLocalPlaybackBeforePendingPipelineCleanupCompletes()
    {
        var stopGate = new TaskCompletionSource<bool>(TaskCreationOptions.RunContinuationsAsynchronously);
        var pipelines = new FakePipelineFactory(writePlaylist: true, stopGate: stopGate);
        await using var engine = Engine(new FakeProvider(), pipelines);
        var started = await engine.StartLocalViewAsync(10);
        var uri = new Uri(new Uri("https://semyra.example/"), started.PlaybackUrl);

        var stop = engine.StopAsync();

        Assert.False(stop.IsCompleted);
        Assert.False(engine.TryReadLocalPlayback(uri, "GET", out _));
        await pipelines.Pipelines[0].StopStarted.Task.WaitAsync(TimeSpan.FromSeconds(2));
        stopGate.SetResult(true);
        await stop;
        Assert.True(pipelines.Pipelines[0].Stopped);
    }

    [Fact]
    public async Task LocalPlaybackHandlerRequiresConfiguredOriginAndActiveAccount()
    {
        var media = Engine(new FakeProvider(), new FakePipelineFactory(writePlaylist: true));
        using var host = new HostEngine(media);
        host.Start();
        var started = await host.StartIptvLocalViewAsync(10, default);
        var handler = new LocalPlaybackRequestHandler(new Uri("https://semyra.example/"), host);
        var segmentUrl = started.PlaybackUrl.Replace("index.m3u8", "segment00001.ts", StringComparison.Ordinal);

        Assert.True(handler.TryRead("https://semyra.example" + started.PlaybackUrl, "GET", out _));
        Assert.False(handler.TryRead("https://evil.example" + started.PlaybackUrl, "GET", out _));
        Assert.Equal(LocalPlaybackRequestKind.PlaybackManifest, handler.Classify("https://semyra.example" + started.PlaybackUrl, "GET"));
        Assert.Equal(LocalPlaybackRequestKind.PlaybackManifest, handler.Classify("https://semyra.example" + started.PlaybackUrl, "HEAD"));
        Assert.Equal(LocalPlaybackRequestKind.PlaybackSegment, handler.Classify("https://semyra.example" + segmentUrl, "GET"));
        Assert.Equal(LocalPlaybackRequestKind.PlaybackInvalid, handler.Classify("https://semyra.example" + started.PlaybackUrl, "POST"));
        Assert.Equal(LocalPlaybackRequestKind.PlaybackInvalid, handler.Classify(
            "https://semyra.example/__desktop/playback/" + new string('b', 64) + "/index.m3u8", "GET"));
        Assert.Equal(LocalPlaybackRequestKind.PlaybackInvalid, handler.Classify(
            "https://semyra.example" + started.PlaybackUrl.Replace("index.m3u8", "%2e%2e%2fsecret", StringComparison.Ordinal), "GET"));

        foreach (var path in new[]
        {
            "/logout", "/", "/rooms", "/desktop/iptv", "/login",
            "/desktop/account-context", "/assets/css/app.css", "/assets/js/app.js", "/assets/images/logo.png",
        })
        {
            var method = path == "/logout" ? "POST" : "GET";
            Assert.Equal(LocalPlaybackRequestKind.NotPlayback, handler.Classify("https://semyra.example" + path, method));
        }

        await host.StopAsync();
        Assert.False(handler.TryRead("https://semyra.example" + started.PlaybackUrl, "GET", out _));
        Assert.Equal(LocalPlaybackRequestKind.PlaybackInvalid, handler.Classify("https://semyra.example" + started.PlaybackUrl, "GET"));
        Assert.Equal(LocalPlaybackRequestKind.NotPlayback, handler.Classify("https://semyra.example/logout", "POST"));
    }

    [Fact]
    public async Task LocalViewAndPublishReplaceEachOtherWithoutConcurrentPipelines()
    {
        var pipelines = new FakePipelineFactory(writePlaylist: true);
        var publish = new FakePublishClient();
        using var engine = new MediaEngine(
            Resolve, GStreamerRuntime.AvailableForTests(), new FakeProvider(), pipelines,
            publishClient: publish);

        var view = await engine.StartLocalViewAsync(10);
        var oldUrl = new Uri(new Uri("https://semyra.example/"), view.PlaybackUrl);
        var publishing = await engine.StartPublishAsync(10, () => Authorization("a"));

        Assert.Equal("publish", publishing.Mode);
        Assert.True(pipelines.Pipelines[0].Stopped);
        Assert.False(engine.TryReadLocalPlayback(oldUrl, "GET", out _));
        Assert.Equal(2, pipelines.CreateCount);

        var nextView = await engine.StartLocalViewAsync(20);

        Assert.Equal("view", nextView.Snapshot.Mode);
        Assert.True(pipelines.Pipelines[1].Stopped);
        Assert.Single(publish.Stops);
        Assert.Equal(3, pipelines.CreateCount);
    }

    [Fact]
    public async Task LocalViewReconnectKeepsTokenAndResetsOldFragments()
    {
        var failure = new TaskCompletionSource<bool>(TaskCreationOptions.RunContinuationsAsynchronously);
        var delays = new List<TimeSpan>();
        var pipelines = new FakePipelineFactory(writePlaylist: true, pumpFailureGate: failure);
        using var engine = Engine(new FakeProvider(), pipelines, (delay, token) =>
        {
            delays.Add(delay);
            return delay < TimeSpan.FromSeconds(1) ? Task.Delay(1, token) : Task.CompletedTask;
        });
        var started = await engine.StartLocalViewAsync(10);
        var url = new Uri(new Uri("https://semyra.example/"), started.PlaybackUrl);

        failure.SetResult(true);
        for (var index = 0; index < 100 && engine.Snapshot().Attempt < 2; index++) await Task.Delay(5);

        Assert.Equal("streaming", engine.Snapshot().State);
        Assert.Equal(2, engine.Snapshot().Attempt);
        Assert.True(engine.TryReadLocalPlayback(url, "GET", out _));
        Assert.Contains(TimeSpan.FromSeconds(1), delays);
        Assert.Equal(2, pipelines.CreateCount);
        Assert.True(pipelines.Pipelines[0].Stopped);
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

        var first = await engine.StartAsync(10);
        var duplicate = await engine.StartAsync(10);
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

        var start = engine.StartAsync(10);
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

        await engine.StartAsync(10);
        var replacement = await engine.StartAsync(20);

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

        var result = await engine.StartAsync(10);

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

        Assert.Equal("streaming", (await engine.StartAsync(10)).State);
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
        await host.StartIptvMediaAsync(10, default);

        await host.StopAsync();
        await Task.Delay(20);

        Assert.Equal(HostEngineState.Stopped, host.State);
        Assert.Equal("idle", host.IptvMediaSnapshot().State);
        Assert.Equal(1, pipelines.CreateCount);
        Assert.True(pipelines.Pipelines[0].Stopped);
    }

    [Fact]
    public async Task RuntimeUnavailableFailsSafelyAndCapabilityIsConditional()
    {
        var unavailable = GStreamerRuntime.Discover(Path.Combine(Path.GetTempPath(), Guid.NewGuid().ToString("N")), new FakeCommandRunner());
        var media = new MediaEngine(
            Resolve,
            unavailable,
            new FakeProvider(),
            new FakePipelineFactory());
        using var host = new HostEngine(media: media);
        host.Start();

        var exception = await Assert.ThrowsAsync<MediaEngineException>(() => host.StartIptvMediaAsync(10, default));

        Assert.Equal("media_runtime_unavailable", exception.Code);
        Assert.DoesNotContain("iptv.play", host.Snapshot().Capabilities);

        var availableMedia = Engine(new FakeProvider(), new FakePipelineFactory());
        using var availableHost = new HostEngine(media: availableMedia);
        availableHost.Start();
        Assert.Contains("iptv.play", availableHost.Snapshot().Capabilities);
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
        return new MediaEngine(Resolve, GStreamerRuntime.AvailableForTests(), provider, pipelines, delay);
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
        bool writePlaylist = false,
        TaskCompletionSource<bool>? pumpFailureGate = null,
        TaskCompletionSource<bool>? stopGate = null) : IMediaPipelineFactory
    {
        public List<FakePipeline> Pipelines { get; } = [];
        public int CreateCount => Pipelines.Count;
        public IMediaPipeline Create()
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
        public IMediaPipeline CreatePublish(Uri whipEndpoint) => Create();
        public IMediaPipeline CreateLocalView(string outputDirectory)
        {
            if (writePlaylist)
            {
                File.WriteAllText(Path.Combine(outputDirectory, "index.m3u8"), "#EXTM3U\nsegment00001.ts\n");
                File.WriteAllBytes(Path.Combine(outputDirectory, "segment00001.ts"), [0x47, 0x00]);
            }
            return Create();
        }
    }

    private sealed class FakePublishClient : IDesktopPublishClient
    {
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
