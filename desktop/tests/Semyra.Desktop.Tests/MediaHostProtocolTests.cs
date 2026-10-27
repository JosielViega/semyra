using System.Text.Json;
using Semyra.MediaHost;

namespace Semyra.Desktop.Tests;

public sealed class MediaHostProtocolTests
{
    private const string Session = "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa";

    [Fact]
    public void AcceptsAllowlistedCommandsWithoutSecretsInArguments()
    {
        Assert.True(MediaHostProtocol.TryParse($$"""{"command":"start","sessionId":"{{Session}}","sourceUrl":"http://127.0.0.1:8123/token/media","whipEndpoint":"https://whip.example/ingress/token"}""", out var start));
        Assert.Equal("start", start.Command);
        Assert.True(MediaHostProtocol.TryParse($$"""{"command":"pause","sessionId":"{{Session}}"}""", out _));
        Assert.True(MediaHostProtocol.TryParse($$"""{"command":"play","sessionId":"{{Session}}"}""", out _));
        Assert.True(MediaHostProtocol.TryParse($$"""{"command":"snapshot","sessionId":"{{Session}}"}""", out _));
        Assert.True(MediaHostProtocol.TryParse($$"""{"command":"stop","sessionId":"{{Session}}"}""", out _));
        Assert.True(MediaHostProtocol.TryParse($$"""{"command":"seek","sessionId":"{{Session}}","positionMs":60000}""", out var seek));
        Assert.Equal(60000, seek.PositionMs);
    }

    [Fact]
    public void DesktopJsonShapeRoundTripsThroughStrictAllowlist()
    {
        var options = new JsonSerializerOptions(JsonSerializerDefaults.Web)
        {
            DefaultIgnoreCondition = System.Text.Json.Serialization.JsonIgnoreCondition.WhenWritingNull,
        };
        var pauseJson = JsonSerializer.Serialize(new MediaHostCommand("pause", Session), options);
        var seekJson = JsonSerializer.Serialize(new MediaHostCommand("seek", Session, PositionMs: 60_000), options);
        Assert.True(MediaHostProtocol.TryParse(pauseJson, out var pause));
        Assert.Equal("pause", pause.Command);
        Assert.True(MediaHostProtocol.TryParse(seekJson, out var seek));
        Assert.Equal(60_000, seek.PositionMs);
        Assert.DoesNotContain("sourceUrl", pauseJson, StringComparison.Ordinal);
        Assert.DoesNotContain("whipEndpoint", seekJson, StringComparison.Ordinal);
    }

    [Theory]
    [InlineData("")]
    [InlineData("not-json")]
    [InlineData("{\"command\":\"unknown\",\"sessionId\":\"aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa\"}")]
    [InlineData("{\"command\":\"start\",\"sessionId\":\"aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa\",\"sourceUrl\":\"https://provider.example/private\",\"whipEndpoint\":\"https://whip.example/x\"}")]
    [InlineData("{\"command\":\"pause\",\"sessionId\":\"aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa\",\"sourceUrl\":\"https://secret.example\"}")]
    [InlineData("{\"command\":\"seek\",\"sessionId\":\"aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa\",\"positionMs\":-1}")]
    public void RejectsInvalidUnknownOrSecretBearingCommands(string json)
    {
        Assert.False(MediaHostProtocol.TryParse(json, out _));
    }

    [Fact]
    public void SnapshotSerializationNeverContainsSourceOrWhipFields()
    {
        var json = MediaHostProtocol.Serialize(new(true, "paused", 30_000, 120_000, true, Session));
        Assert.DoesNotContain("source", json, StringComparison.OrdinalIgnoreCase);
        Assert.DoesNotContain("whip", json, StringComparison.OrdinalIgnoreCase);
        using var parsed = JsonDocument.Parse(json);
        Assert.Equal(30_000, parsed.RootElement.GetProperty("positionMs").GetInt64());
        Assert.Equal(120_000, parsed.RootElement.GetProperty("durationMs").GetInt64());
    }

    [Fact]
    public void ServerRoutesLifecycleAndRejectsStaleSession()
    {
        using var publisher = new FakePublisher();
        var started = MediaHostServer.Handle(new("start", Session, "http://127.0.0.1:8123/token/media", "https://whip.example/ingress"), publisher);
        Assert.True(started.Ok);
        Assert.Equal("playing", started.State);
        Assert.Equal("paused", MediaHostServer.Handle(new("pause", Session), publisher).State);
        Assert.Equal("playing", MediaHostServer.Handle(new("play", Session), publisher).State);
        var seek = MediaHostServer.Handle(new("seek", Session, PositionMs: 60_000), publisher);
        Assert.Equal(60_000, seek.PositionMs);
        Assert.Equal(120_000, seek.DurationMs);
        Assert.False(MediaHostServer.Handle(new("pause", new string('b', 32)), publisher).Ok);
        Assert.Equal("stale_session", MediaHostServer.Handle(new("pause", new string('b', 32)), publisher).ErrorCode);
        Assert.Equal("idle", MediaHostServer.Handle(new("stop", Session), publisher).State);
    }

    [Fact]
    public void ServerSurfacesEosAndErrorSnapshots()
    {
        using var publisher = new FakePublisher();
        _ = publisher.Start(Session, new Uri("http://127.0.0.1/media"), new Uri("https://whip.example/x"));
        publisher.State = "ended";
        Assert.Equal("ended", MediaHostServer.Handle(new("snapshot", Session), publisher).State);
        publisher.Error = "pipeline_error";
        Assert.False(MediaHostServer.Handle(new("snapshot", Session), publisher).Ok);
    }

    [Fact]
    public void NativePipelinePreservesApprovedVodQualityAndWhipTopology()
    {
        var description = GStreamerVodPublisher.BuildPipelineDescription(
            new Uri("http://127.0.0.1:8123/opaque/media"),
            new Uri("https://whip.example/opaque"));
        foreach (var expected in new[] {
            "souphttpsrc", "qtdemux", "avdec_h264", "videoconvert",
            "x264enc bitrate=2800", "speed-preset=veryfast", "tune=zerolatency",
            "bframes=0", "key-int-max=48", "cabac=false", "dct8x8=false", "byte-stream=true",
            "profile=constrained-baseline", "clocksync", "rtph264pay", "aacparse", "avdec_aac",
            "audioconvert", "audioresample", "opusenc bitrate=96000", "rtpopuspay", "whipsink",
            "intervideosink", "intervideosrc", "interaudiosink", "interaudiosrc"
        }) Assert.Contains(expected, description, StringComparison.Ordinal);
        Assert.Equal(2, System.Text.RegularExpressions.Regex.Matches(description, "single-segment=true").Count);
        Assert.Equal(2, System.Text.RegularExpressions.Regex.Matches(description, "perfect-rtptime=false").Count);
        Assert.Equal(2, System.Text.RegularExpressions.Regex.Matches(description,
            "audio/x-raw,format=S16LE,rate=48000,channels=2,layout=interleaved").Count);
        Assert.Contains("buffer-time=200000000 latency-time=40000000 period-time=10000000", description, StringComparison.Ordinal);
        Assert.Contains("identity name=video_timeline", description, StringComparison.Ordinal);
        Assert.Contains("identity name=audio_timeline", description, StringComparison.Ordinal);
        Assert.DoesNotContain("livesync", description, StringComparison.Ordinal);
        Assert.DoesNotContain("audiomixer", description, StringComparison.Ordinal);
        Assert.DoesNotContain("audiotestsrc", description, StringComparison.Ordinal);
        Assert.DoesNotContain("gst-launch", description, StringComparison.OrdinalIgnoreCase);
    }

    [Theory]
    [InlineData("playing", 10_000, 40_000)]
    [InlineData("playing", 60_000, 30_000)]
    [InlineData("paused", 20_000, 60_000)]
    public void NativeSeekWaitsForAsyncDoneAndPreservesState(string state, long initialMs, long requestedMs)
    {
        var gst = new FakeGStreamerApi {
            PositionNs = initialMs * 1_000_000,
            ReturnZeroPositionWhenPaused = state == "paused",
        };
        using var publisher = StartNative(gst);
        if (state == "paused") Assert.True(publisher.Pause(Session).Ok);

        var result = publisher.Seek(Session, requestedMs);

        Assert.True(result.Ok);
        Assert.Equal(state, result.State);
        Assert.Equal(requestedMs, result.PositionMs);
        Assert.Equal(requestedMs * 1_000_000, gst.LastSeekNs);
        Assert.Equal(3, gst.LastSeekFlags);
        Assert.Equal(1, gst.AsyncDoneWaits);
        Assert.Equal(2_000_000_000UL, gst.LastAsyncDoneTimeout);
        Assert.Contains($"timestamp-offset={gst.VideoBefore.Timestamp + 3_750}", gst.LastPipelineDescription, StringComparison.Ordinal);
        Assert.Contains($"timestamp-offset={gst.AudioBefore.Timestamp + 960}", gst.LastPipelineDescription, StringComparison.Ordinal);
    }

    [Fact]
    public void NativeSeekTimeoutDoesNotReportSuccess()
    {
        var gst = new FakeGStreamerApi { CompleteSeek = false, PositionNs = 10_000_000_000 };
        using var publisher = StartNative(gst);

        var result = publisher.Seek(Session, 40_000);

        Assert.False(result.Ok);
        Assert.Equal("seek_timeout", result.ErrorCode);
        Assert.Equal("failed", result.State);
    }

    [Fact]
    public async Task NativePublisherRejectsConcurrentSeekWhileFirstAwaitsAsyncDone()
    {
        var gst = new FakeGStreamerApi { BlockSeekCompletion = true };
        using var publisher = StartNative(gst);
        var first = Task.Run(() => publisher.Seek(Session, 40_000));
        Assert.True(gst.SeekWaitEntered.Wait(TimeSpan.FromSeconds(2)));

        var second = publisher.Seek(Session, 30_000);
        gst.ReleaseSeek.Set();
        var completed = await first;

        Assert.False(second.Ok);
        Assert.Equal("seek_in_progress", second.ErrorCode);
        Assert.True(completed.Ok);
    }

    [Fact]
    public void NativePausePlayWaitsForContentStateAndKeepsPublishPipelinePlaying()
    {
        var gst = new FakeGStreamerApi();
        using var publisher = StartNative(gst);

        Assert.True(publisher.Pause(Session).Ok);
        Thread.Sleep(25);
        var resumed = publisher.Play(Session);

        Assert.True(resumed.Ok);
        Assert.Equal("playing", resumed.State);
        Assert.Contains(GstState.Paused, gst.GetStateTargets);
        Assert.Contains(GstState.Playing, gst.GetStateTargets);
        Assert.Contains(gst.ContentPipeline, gst.StateChanges
            .Where(change => change.State == GstState.Paused)
            .Select(change => change.Element));
        Assert.DoesNotContain(gst.PublishPipeline, gst.StateChanges
            .Where(change => change.State == GstState.Paused)
            .Select(change => change.Element));
        Assert.Equal(GstState.Playing, gst.StateOf(gst.PublishPipeline));
        var duplicatePlayCalls = gst.GetStateTargets.Count;
        Assert.True(publisher.Play(Session).Ok);
        Assert.Equal(duplicatePlayCalls, gst.GetStateTargets.Count);
    }

    [Fact]
    public void NativePauseKeepsCanonicalPositionFrozenWhilePublishPipelineRuns()
    {
        var gst = new FakeGStreamerApi { PositionNs = 12_000_000_000 };
        using var publisher = StartNative(gst);

        var paused = publisher.Pause(Session);
        gst.PositionNs = 17_000_000_000;
        var snapshot = publisher.Snapshot(Session);

        Assert.Equal(12_000, paused.PositionMs);
        Assert.Equal(paused.PositionMs, snapshot.PositionMs);
        Assert.Equal(GstState.Playing, gst.StateOf(gst.PublishPipeline));
    }

    [Fact]
    public void NativeTenPausePlayCyclesNeverPauseOrReplacePublishPipeline()
    {
        var gst = new FakeGStreamerApi();
        using var publisher = StartNative(gst);
        var publishPipeline = gst.PublishPipeline;

        for (var cycle = 0; cycle < 10; cycle++)
        {
            Assert.True(publisher.Pause(Session).Ok);
            Assert.True(publisher.Play(Session).Ok);
        }

        Assert.Equal(publishPipeline, gst.PublishPipeline);
        Assert.Equal(GstState.Playing, gst.StateOf(publishPipeline));
        Assert.Equal(10, gst.StateChanges.Count(change =>
            change.Element == gst.ContentPipeline && change.State == GstState.Paused));
        Assert.DoesNotContain(gst.StateChanges, change =>
            change.Element == publishPipeline && change.State == GstState.Paused);
    }

    private static GStreamerVodPublisher StartNative(FakeGStreamerApi gst)
    {
        var publisher = new GStreamerVodPublisher(gst);
        var result = publisher.Start(Session, new Uri("http://127.0.0.1/opaque/media"), new Uri("https://whip.example/opaque"));
        Assert.True(result.Ok);
        return publisher;
    }

    private sealed class FakePublisher : IMediaHostPublisher
    {
        private string? _session;
        internal string State { get; set; } = "idle";
        internal string? Error { get; set; }
        private long _position;
        public MediaHostSnapshot Start(string sessionId, Uri source, Uri whip) { _session = sessionId; State = "playing"; return Snapshot(sessionId); }
        public MediaHostSnapshot Pause(string sessionId) => Set(sessionId, "paused");
        public MediaHostSnapshot Play(string sessionId) => Set(sessionId, "playing");
        public MediaHostSnapshot Seek(string sessionId, long positionMs) { if (!Matches(sessionId)) return Stale(sessionId); _position = positionMs; return Snapshot(sessionId); }
        public MediaHostSnapshot Snapshot(string? sessionId = null) => sessionId is not null && !Matches(sessionId)
            ? Stale(sessionId) : new(Error is null, State, _position, 120_000, true, _session, Error);
        public MediaHostSnapshot Stop(string? sessionId = null) { if (sessionId is not null && !Matches(sessionId)) return Stale(sessionId); State = "idle"; return new(true, State, 0, 0, false, _session); }
        private MediaHostSnapshot Set(string sessionId, string state) { if (!Matches(sessionId)) return Stale(sessionId); State = state; return Snapshot(sessionId); }
        private bool Matches(string sessionId) => string.Equals(_session, sessionId, StringComparison.Ordinal);
        private static MediaHostSnapshot Stale(string sessionId) => new(false, "failed", 0, 0, false, sessionId, "stale_session");
        public void Dispose() { }
    }

    private sealed class FakeGStreamerApi : IGStreamerApi
    {
        private const ulong MessageAsyncDone = 1UL << 21;
        internal long PositionNs { get; set; } = 1_000_000_000;
        internal long DurationNs { get; set; } = 120_000_000_000;
        internal long LastSeekNs { get; private set; }
        internal int LastSeekFlags { get; private set; }
        internal int AsyncDoneWaits { get; private set; }
        internal ulong LastAsyncDoneTimeout { get; private set; }
        internal bool CompleteSeek { get; set; } = true;
        internal bool BlockSeekCompletion { get; set; }
        internal bool ReturnZeroPositionWhenPaused { get; set; }
        internal ManualResetEventSlim SeekWaitEntered { get; } = new(false);
        internal ManualResetEventSlim ReleaseSeek { get; } = new(false);
        private readonly Dictionary<IntPtr, GstState> _states = [];
        private int _nextHandle;
        internal RtpPayloaderStats VideoBefore { get; } = new(10_000, 1, 100, 1);
        internal RtpPayloaderStats AudioBefore { get; } = new(20_000, 1, 200, 2);
        internal List<string> PipelineDescriptions { get; } = [];
        internal string LastPipelineDescription => string.Join(' ', PipelineDescriptions);
        internal List<GstState> GetStateTargets { get; } = [];
        internal List<(IntPtr Element, GstState State)> StateChanges { get; } = [];
        internal IntPtr ContentPipeline { get; private set; }
        internal IntPtr PublishPipeline { get; private set; }
        private bool _seeked;

        public void Init() { }
        public IntPtr ParseLaunch(string description, out IntPtr error)
        {
            var handle = new IntPtr(++_nextHandle);
            PipelineDescriptions.Add(description);
            _states[handle] = GstState.Null;
            if (description.Contains("intervideosink", StringComparison.Ordinal)) ContentPipeline = handle;
            if (description.Contains("whipsink", StringComparison.Ordinal)) PublishPipeline = handle;
            error = IntPtr.Zero;
            return handle;
        }
        public GstStateChangeReturn SetState(IntPtr element, GstState state)
        {
            _states[element] = state;
            StateChanges.Add((element, state));
            return GstStateChangeReturn.Success;
        }
        public GstStateChangeReturn GetState(IntPtr element, out GstState state, out GstState pending, ulong timeout)
        { state = StateOf(element); pending = GstState.VoidPending; GetStateTargets.Add(state); return GstStateChangeReturn.Success; }
        public bool SeekSimple(IntPtr element, int format, int seekFlags, long position)
        { LastSeekFlags = seekFlags; LastSeekNs = position; if (CompleteSeek) PositionNs = position; _seeked = CompleteSeek; return true; }
        public bool QueryPosition(IntPtr element, int format, out long position)
        { position = ReturnZeroPositionWhenPaused && StateOf(element) == GstState.Paused ? 0 : PositionNs; return true; }
        public bool QueryDuration(IntPtr element, int format, out long duration) { duration = DurationNs; return true; }
        public IntPtr GetBus(IntPtr element) => (IntPtr)2;
        public IntPtr BusTimedPopFiltered(IntPtr bus, ulong timeout, ulong types)
        {
            if (types != MessageAsyncDone || timeout == 0) return IntPtr.Zero;
            AsyncDoneWaits++;
            LastAsyncDoneTimeout = timeout;
            if (BlockSeekCompletion)
            {
                SeekWaitEntered.Set();
                if (!ReleaseSeek.Wait(TimeSpan.FromSeconds(2))) return IntPtr.Zero;
            }
            return CompleteSeek ? (IntPtr)3 : IntPtr.Zero;
        }
        public void MiniObjectUnref(IntPtr message) { }
        public void ObjectUnref(IntPtr value) { }
        public void ErrorFree(IntPtr error) { }
        public RtpPayloaderStats? ReadPayloaderStats(IntPtr pipeline, string name)
        {
            var before = name == "video_pay" ? VideoBefore : AudioBefore;
            return !_seeked || StateOf(pipeline) == GstState.Paused
                ? before
                : before with { Timestamp = before.Timestamp + 10_000, Seqnum = before.Seqnum + 1 };
        }
        internal GstState StateOf(IntPtr element) => _states.TryGetValue(element, out var state) ? state : GstState.Null;
    }
}
