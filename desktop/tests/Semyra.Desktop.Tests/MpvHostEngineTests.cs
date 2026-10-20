using System.Text;
using System.Text.Json;
using Semyra.Desktop.Host;
using Semyra.Desktop.Iptv;
using Semyra.Desktop.Media;

namespace Semyra.Desktop.Tests;

public sealed class MpvHostEngineTests : IDisposable
{
    private readonly string _root = Path.Combine(Path.GetTempPath(), "semyra-mpv-host-" + Guid.NewGuid().ToString("N"));

    [Fact]
    public async Task ZeroNativeHostHandleIsRejected()
    {
        await using var host = new HostEngine();
        Assert.Throws<ArgumentOutOfRangeException>(() => host.SetNativeVideoHost(0));
    }

    [Fact]
    public async Task MissingMpvRuntimeDisablesLocalPlaybackWithoutGStreamerFallback()
    {
        await using var host = new HostEngine(
            _ => throw new InvalidOperationException("Catalog must not be opened."),
            _ => throw new InvalidOperationException("GStreamer must not be selected for local playback."),
            MpvRuntime.Unavailable());
        host.SetNativeVideoHost((nint)123);

        Assert.Equal(LocalPlaybackEngine.Unavailable, host.LocalPlaybackEngine);
        var exception = await Assert.ThrowsAsync<MediaEngineException>(() =>
            host.StartIptvLocalViewAsync(1, default));
        Assert.Equal("mpv_runtime_unavailable", exception.Code);
        Assert.DoesNotContain("iptv.local-view", host.Snapshot().Capabilities);
    }

    [Theory]
    [InlineData(true, false, true, false)]
    [InlineData(false, true, false, true)]
    [InlineData(true, true, true, true)]
    public async Task LocalViewAndPublishCapabilitiesComeFromIndependentRuntimes(
        bool mpvAvailable,
        bool gstreamerAvailable,
        bool expectLocalView,
        bool expectPublish)
    {
        Directory.CreateDirectory(_root);
        var profile = new string((char)('b' + Directory.GetFiles(_root, "*.db").Length), 64);
        var protector = new Protector();
        await using var host = new HostEngine(
            _ => new IptvCatalogService(new IptvStore(Path.Combine(_root, profile + ".db"), protector), protector, new M3uParser()),
            catalog => MediaEngine.CreateDefault(catalog, gstreamerAvailable
                ? GStreamerRuntime.AvailableForTests()
                : GStreamerRuntime.UnavailableForTests()),
            mpvAvailable
                ? new MpvRuntime(true, "mpv.exe", "test", "test", null)
                : MpvRuntime.Unavailable());
        host.SetNativeVideoHost((nint)123);
        host.Start();
        await host.ActivateAccountAsync(profile);

        Assert.Equal(expectLocalView, host.Snapshot().Capabilities.Contains("iptv.local-view"));
        Assert.Equal(expectPublish, host.Snapshot().Capabilities.Contains("livekit.publish"));
        Assert.Equal(expectPublish, host.Snapshot().Capabilities.Contains("media.whip"));
    }

    [Fact]
    public async Task PlaybackConfirmationRecordsRecentAndAccountClearKillsSession()
    {
        var protector = new Protector();
        var store = new IptvStore(Path.Combine(_root, "semyra.db"), protector);
        store.Initialize();
        var source = store.AddSource("Source", IptvSourceType.M3uUrl, "https://example.invalid/list");
        await store.ReplaceChannelsAsync(source.Id, Channels(), default);
        using var catalog = new IptvCatalogService(store, protector, new M3uParser());
        var process = new FakeProcess();
        var player = new MpvPlayer(
            new MpvRuntime(true, "mpv.exe", "test", "test", null),
            (nint)123,
            (_, _) => process);
        await using var host = new HostEngine(
            _ => catalog,
            value => MediaEngine.CreateDefault(value, GStreamerRuntime.AvailableForTests()),
            new MpvRuntime(true, "mpv.exe", "test", "test", null),
            (_, _) => player);
        host.SetNativeVideoHost((nint)123);
        host.Start();
        var context = await host.ActivateAccountAsync(new string('a', 64));
        Assert.True(host.IsCurrentAccountContext(context));
        var channel = Assert.Single(store.SearchChannels(source.Id, "", null, 0, 50).Channels);

        var started = await host.StartIptvLocalViewAsync(channel.Id, default);
        var unfencedStop = await Assert.ThrowsAsync<MediaEngineException>(host.StopIptvMediaAsync);
        Assert.Equal("playback_session_required", unfencedStop.Code);
        Assert.Empty(store.ListRecents(source.Id));
        process.Emit("""{"event":"start-file"}""");
        process.Emit("""{"event":"playback-restart"}""");
        Assert.Empty(store.ListRecents(source.Id));
        process.Emit("""{"event":"property-change","name":"time-pos","data":1}""");
        Assert.Equal(channel.Id, Assert.Single(store.ListRecents(source.Id)).ChannelId);

        MpvGeometry? observed = null;
        host.NativeVideoGeometryChanged += (_, geometry) => observed = geometry;
        host.UpdateMpvGeometry(started.SessionId!, new MpvGeometry(10, 20, 800, 450, 1200, 800));
        Assert.NotNull(observed);
        var invalid = Assert.Throws<MediaEngineException>(() =>
            host.UpdateMpvGeometry(started.SessionId!, new MpvGeometry(-1, 20, 800, 450, 1200, 800)));
        Assert.Equal("invalid_player_geometry", invalid.Code);

        var fullscreenStates = new List<bool>();
        host.NativeVideoFullscreenChanged += (_, fullscreen) => fullscreenStates.Add(fullscreen);
        Assert.True(host.SetMpvFullscreen(started.SessionId!, true).Fullscreen);
        Assert.False(host.SetMpvFullscreen(started.SessionId!, false).Fullscreen);
        Assert.Equal([true, false], fullscreenStates);

        var hidden = false;
        host.NativeVideoVisibilityChanged += (_, visible) => hidden |= !visible;
        await host.ClearAccountAsync();
        Assert.True(process.Disposed);
        Assert.True(hidden);
        Assert.False(host.IsCurrentAccountContext(context));
        Assert.Equal("idle", host.IptvMediaSnapshot().State);
    }

    [Fact]
    public async Task VodPlaybackUsesMpvAndDoesNotRecordALiveRecent()
    {
        var protector = new Protector();
        var store = new IptvStore(Path.Combine(_root, "vod.db"), protector); store.Initialize();
        var source = store.AddSource("Xtream", IptvSourceType.Xtream, "{\"version\":1,\"baseUrl\":\"https://provider.example\",\"username\":\"alice\",\"password\":\"secret\"}");
        store.ReplaceVodCatalog(source.Id, new XtreamVodImport([], [new XtreamMovie("200", "Movie A", null, null, "mp4", null, null, null, null, null, null)], [new XtreamSeries("500", "Series A", null, null, null, null, null, null, null, null)]));
        using var catalog = new IptvCatalogService(store, protector, new M3uParser());
        var process = new FakeProcess();
        var player = new MpvPlayer(new MpvRuntime(true, "mpv.exe", "test", "test", null), (nint)123, (_, _) => process);
        await using var host = new HostEngine(_ => catalog, value => MediaEngine.CreateDefault(value, GStreamerRuntime.UnavailableForTests()), new MpvRuntime(true, "mpv.exe", "test", "test", null), (_, _) => player);
        host.SetNativeVideoHost((nint)123); host.Start(); await host.ActivateAccountAsync(new string('b', 64));
        var movie = Assert.Single(catalog.SearchMovies(source.Id, "", null, 0, 50).Items);

        await host.StartIptvVodViewAsync("movie", movie.Id, default);
        Assert.Contains("/movie/alice/secret/200.mp4", process.Commands.Last(item => item.Command == "loadfile").Arguments.OfType<string>().First());
        process.Emit("""{"event":"start-file"}"""); process.Emit("""{"event":"playback-restart"}"""); process.Emit("""{"event":"property-change","name":"time-pos","data":1}""");

        Assert.Empty(store.ListRecents(source.Id));
        var series = Assert.Single(catalog.SearchSeries(source.Id, "", null, 0, 50).Items);
        var details = store.ReplaceSeriesEpisodes(series.Id, [new XtreamEpisode("301", 1, 2, "Second", "mkv", "42:00", null, null)]);
        await host.StartIptvVodViewAsync("episode", Assert.Single(details.Episodes).Id, default);
        Assert.Contains("/series/alice/secret/301.mkv", process.Commands.Last(item => item.Command == "loadfile").Arguments.OfType<string>().First());
        Assert.Contains("S01E02", host.IptvMediaSnapshot().ChannelName);
        Assert.DoesNotContain("alice", System.Text.Json.JsonSerializer.Serialize(host.IptvMediaSnapshot()));
        Assert.DoesNotContain("secret", System.Text.Json.JsonSerializer.Serialize(host.IptvMediaSnapshot()));
    }

    private static async IAsyncEnumerable<ParsedM3uChannel> Channels()
    {
        yield return new ParsedM3uChannel("Movie", "https://example.invalid/private/movie", "movie-1", null, "Movies");
        await Task.Yield();
    }

    public void Dispose()
    {
        if (Directory.Exists(_root)) Directory.Delete(_root, true);
    }

    private sealed class Protector : ISecretProtector
    {
        public byte[] Protect(string value) => Encoding.UTF8.GetBytes(value);
        public string Unprotect(byte[] value) => Encoding.UTF8.GetString(value);
    }

    private sealed class FakeProcess : IMpvProcess
    {
        public event EventHandler<JsonElement>? MessageReceived;
        public event EventHandler? Exited { add { } remove { } }
        public bool IsRunning { get; private set; }
        public int? ProcessId => 7;
        public long? WorkingSetBytes => 1;
        public string PipeName => "semyra-mpv-test";
        public IReadOnlyList<string> Arguments => ["--idle=yes"];
        public bool Disposed { get; private set; }
        public List<(string Command, IReadOnlyList<object?> Arguments)> Commands { get; } = [];
        public Task StartAsync(CancellationToken cancellationToken) { IsRunning = true; return Task.CompletedTask; }
        public Task<JsonElement> CommandAsync(string command, IReadOnlyList<object?> arguments, CancellationToken cancellationToken)
        {
            Commands.Add((command, arguments));
            return Task.FromResult(JsonDocument.Parse("{}").RootElement.Clone());
        }
        public void Emit(string json)
        {
            using var document = JsonDocument.Parse(json);
            MessageReceived?.Invoke(this, document.RootElement.Clone());
        }
        public ValueTask DisposeAsync() { Disposed = true; IsRunning = false; return ValueTask.CompletedTask; }
    }
}
