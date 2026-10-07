using Semyra.Desktop.Host;
using Semyra.Desktop.Iptv;
using Semyra.Desktop.Media;

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

        host.Stop();
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
        TaskCompletionSource<bool>? startGate = null) : IMediaPipelineFactory
    {
        public List<FakePipeline> Pipelines { get; } = [];
        public int CreateCount => Pipelines.Count;
        public IMediaPipeline Create()
        {
            var pipeline = new FakePipeline(
                Pipelines.Count < failedStarts,
                Pipelines.Count < failedPumps,
                startGate);
            Pipelines.Add(pipeline);
            return pipeline;
        }
    }

    private sealed class FakePipeline(
        bool failStart,
        bool failPump,
        TaskCompletionSource<bool>? startGate) : IMediaPipeline
    {
        public bool Stopped { get; private set; }
        public async Task StartAsync(ReadOnlyMemory<byte> initialBuffer, CancellationToken cancellationToken)
        {
            Assert.True(initialBuffer.Length >= 188 * 3);
            if (failStart) throw new MediaEngineException("pipeline_start_failed");
            if (startGate is not null) await startGate.Task.WaitAsync(cancellationToken);
        }
        public Task PumpAsync(Stream providerStream, CancellationToken cancellationToken)
        {
            if (failPump) throw new MediaEngineException("pipeline_exited");
            return Task.Delay(Timeout.InfiniteTimeSpan, cancellationToken);
        }
        public Task StopAsync() { Stopped = true; return Task.CompletedTask; }
        public async ValueTask DisposeAsync() { await StopAsync(); }
    }

    private sealed class FakeCommandRunner : IGStreamerCommandRunner
    {
        public (int ExitCode, string Output) Run(string executable, IReadOnlyList<string> arguments) => (1, string.Empty);
    }
}
