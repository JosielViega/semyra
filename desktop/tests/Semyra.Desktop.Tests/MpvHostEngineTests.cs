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

        var liveLoadCount = process.Commands.Count(command => command.Command == "loadfile");
        process.Emit("""{"event":"property-change","name":"eof-reached","data":true}""");
        await Task.Delay(30);
        Assert.Equal(liveLoadCount, process.Commands.Count(command => command.Command == "loadfile"));
        Assert.Equal("ended", host.IptvMediaSnapshot().State);

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
        process.Emit("""{"event":"start-file"}""");
        process.Emit("""{"event":"property-change","name":"duration","data":100}""");
        process.Emit("""{"event":"property-change","name":"seekable","data":true}""");
        process.Emit("""{"event":"playback-restart"}""");
        process.Emit("""{"event":"property-change","name":"time-pos","data":100}""");
        process.Emit("""{"event":"property-change","name":"eof-reached","data":false}""");
        process.Emit("""{"event":"property-change","name":"eof-reached","data":true}""");
        await Task.Delay(30);

        Assert.Empty(store.ListRecents(source.Id));
        Assert.Single(process.Commands, command => command.Command == "loadfile");
        Assert.Equal("ended", host.IptvMediaSnapshot().State);
        var series = Assert.Single(catalog.SearchSeries(source.Id, "", null, 0, 50).Items);
        var details = store.ReplaceSeriesEpisodes(series.Id, [new XtreamEpisode("301", 1, 2, "Second", "mkv", "42:00", null, null)]);
        await host.StartIptvVodViewAsync("episode", Assert.Single(details.Episodes).Id, default);
        Assert.Contains("/series/alice/secret/301.mkv", process.Commands.Last(item => item.Command == "loadfile").Arguments.OfType<string>().First());
        Assert.Contains("S01E02", host.IptvMediaSnapshot().ChannelName);
        Assert.DoesNotContain("alice", System.Text.Json.JsonSerializer.Serialize(host.IptvMediaSnapshot()));
        Assert.DoesNotContain("secret", System.Text.Json.JsonSerializer.Serialize(host.IptvMediaSnapshot()));
    }

    [Fact]
    public async Task VodResumeUsesMpvIpcAndPeriodicWriterIsSingleAndSessionFenced()
    {
        var protector = new Protector();
        var store = new IptvStore(Path.Combine(_root, "progress.db"), protector);
        store.Initialize();
        var source = store.AddSource("Xtream", IptvSourceType.Xtream, "{\"version\":1,\"baseUrl\":\"https://provider.example\",\"username\":\"alice\",\"password\":\"secret\"}");
        store.ReplaceVodCatalog(source.Id, new XtreamVodImport([], [
            new XtreamMovie("200", "Movie A", null, null, "mp4", null, null, null, null, null, null),
            new XtreamMovie("201", "Movie B", null, null, "mp4", null, null, null, null, null, null),
        ], []));
        var movies = store.SearchMovies(source.Id, "Movie", null, 0, 10).Items;
        store.SaveVodProgress(store.ResolveMovieForPlayback(movies[0].Id), 1800, 7200);

        using var catalog = new IptvCatalogService(store, protector, new M3uParser());
        var process = new FakeProcess();
        var player = new MpvPlayer(new MpvRuntime(true, "mpv.exe", "test", "test", null), (nint)123, (_, _) => process);
        await using var host = new HostEngine(_ => catalog, value => MediaEngine.CreateDefault(value, GStreamerRuntime.UnavailableForTests()),
            new MpvRuntime(true, "mpv.exe", "test", "test", null), (_, _) => player, TimeSpan.FromMilliseconds(25));
        host.SetNativeVideoHost((nint)123); host.Start(); await host.ActivateAccountAsync(new string('c', 64));

        var progressGate = new object();
        var movieAEvents = 0;
        var movieBEvents = 0;
        string? movieASession = null;
        string? movieBSession = null;
        IptvVodProgressChanged? lastMovieAProgress = null;
        IptvVodProgressChanged? lastMovieBProgress = null;
        var movieAProgressReady = new TaskCompletionSource(TaskCreationOptions.RunContinuationsAsynchronously);
        var movieBProgressReady = new TaskCompletionSource(TaskCreationOptions.RunContinuationsAsynchronously);
        host.VodProgressChanged += (_, progress) =>
        {
            lock (progressGate)
            {
                if (progress.MediaId == movies[0].Id && progress.SessionId == movieASession)
                {
                    movieAEvents++;
                    lastMovieAProgress = progress;
                    if (movieAEvents >= 2) movieAProgressReady.TrySetResult();
                }
                else if (progress.MediaId == movies[1].Id && progress.SessionId == movieBSession)
                {
                    movieBEvents++;
                    lastMovieBProgress = progress;
                    if (movieBEvents >= 2) movieBProgressReady.TrySetResult();
                }
            }
        };

        var movieAStarted = await host.StartIptvVodViewAsync("movie", movies[0].Id, default);
        lock (progressGate) movieASession = movieAStarted.SessionId;
        process.Emit("""{"event":"start-file"}""");
        process.Emit("""{"event":"property-change","name":"duration","data":7200}""");
        process.Emit("""{"event":"property-change","name":"seekable","data":true}""");
        process.Emit("""{"event":"playback-restart"}""");
        await WaitUntilAsync(() => process.Commands.Any(command => command.Command == "seek" && Convert.ToDouble(command.Arguments[0]) == 1800));
        Assert.Contains(process.Commands, command => command.Command == "seek" && Convert.ToDouble(command.Arguments[0]) == 1800);

        process.Emit("""{"event":"property-change","name":"time-pos","data":100}""");
        await movieAProgressReady.Task.WaitAsync(TimeSpan.FromSeconds(2));
        lock (progressGate)
        {
            Assert.True(movieAEvents >= 2);
            var progress = Assert.IsType<IptvVodProgressChanged>(lastMovieAProgress);
            Assert.Equal(100, progress.PositionSeconds);
            Assert.Equal(movieAStarted.SessionId, progress.SessionId);
        }

        var movieBStarted = await host.StartIptvVodViewAsync("movie", movies[1].Id, default);
        int movieAEventsAfterSwitch;
        lock (progressGate)
        {
            movieBSession = movieBStarted.SessionId;
            movieAEventsAfterSwitch = movieAEvents;
        }
        Assert.Equal(100, store.ResumePosition(store.ResolveMovieForPlayback(movies[0].Id)));
        process.Emit("""{"event":"start-file"}""");
        process.Emit("""{"event":"property-change","name":"duration","data":1000}""");
        process.Emit("""{"event":"property-change","name":"seekable","data":true}""");
        process.Emit("""{"event":"property-change","name":"time-pos","data":40}""");
        await movieBProgressReady.Task.WaitAsync(TimeSpan.FromSeconds(2));
        lock (progressGate)
        {
            Assert.Equal(movieAEventsAfterSwitch, movieAEvents);
            Assert.True(movieBEvents >= 2);
            var progress = Assert.IsType<IptvVodProgressChanged>(lastMovieBProgress);
            Assert.Equal(40, progress.PositionSeconds);
            Assert.Equal(movieBStarted.SessionId, progress.SessionId);
        }
        await host.ControlMpvAsync(host.IptvMediaSnapshot().SessionId!, "stop", null, default);
        Assert.Equal(40, store.ResumePosition(store.ResolveMovieForPlayback(movies[1].Id)));
        Assert.Equal(100, store.ResumePosition(store.ResolveMovieForPlayback(movies[0].Id)));
    }

    [Fact]
    public async Task EpisodeEofAutoplaysExactlyOnceAcrossSeasonsAndPreservesFullscreen()
    {
        var setup = await EpisodeHostAsync("autoplay.db");
        await using var host = setup.Host;
        var progress = new List<IptvVodProgressChanged>();
        host.VodProgressChanged += (_, value) => progress.Add(value);

        var started = await host.StartIptvVodViewAsync("episode", setup.Episodes[0].Id, default);
        setup.Process.Emit("""{"event":"start-file","playlist_entry_id":10}""");
        setup.Process.Emit("""{"event":"property-change","name":"duration","data":1000}""");
        setup.Process.Emit("""{"event":"property-change","name":"seekable","data":true}""");
        setup.Process.Emit("""{"event":"playback-restart","playlist_entry_id":10}""");
        setup.Process.Emit("""{"event":"property-change","name":"time-pos","data":950}""");
        host.SetMpvFullscreen(started.SessionId!, true);

        await host.ControlMpvAsync(started.SessionId!, "pause", null, default);
        await host.ControlMpvAsync(started.SessionId!, "seek-absolute", 998, default);
        await Task.Delay(30);
        Assert.Single(setup.Process.Commands, command => command.Command == "loadfile");

        setup.Process.Emit("""{"event":"property-change","name":"eof-reached","data":false}""");
        setup.Process.Emit("""{"event":"property-change","name":"eof-reached","data":true}""");
        await WaitUntilAsync(() => setup.Process.Commands.Count(command => command.Command == "loadfile") == 2);
        Assert.Contains("/series/alice/secret/302.mkv", setup.Process.Commands.Last(command => command.Command == "loadfile").Arguments.OfType<string>().First());
        Assert.True(host.IptvMediaSnapshot().Fullscreen);
        Assert.Contains(progress, value => value.MediaId == setup.Episodes[0].Id && value.Completed);

        setup.Process.Emit("""{"event":"end-file","reason":"eof","playlist_entry_id":10}""");
        await Task.Delay(30);
        Assert.Equal(2, setup.Process.Commands.Count(command => command.Command == "loadfile"));

        setup.Process.Emit("""{"event":"start-file","playlist_entry_id":11}""");
        setup.Process.Emit("""{"event":"property-change","name":"duration","data":1000}""");
        setup.Process.Emit("""{"event":"property-change","name":"seekable","data":true}""");
        setup.Process.Emit("""{"event":"property-change","name":"time-pos","data":1000}""");
        setup.Process.Emit("""{"event":"property-change","name":"eof-reached","data":true}""");
        await WaitUntilAsync(() => setup.Process.Commands.Count(command => command.Command == "loadfile") == 3);
        Assert.Contains("/series/alice/secret/401.mkv", setup.Process.Commands.Last(command => command.Command == "loadfile").Arguments.OfType<string>().First());
        Assert.True(host.IptvMediaSnapshot().Fullscreen);

        setup.Process.Emit("""{"event":"start-file","playlist_entry_id":12}""");
        setup.Process.Emit("""{"event":"property-change","name":"duration","data":1000}""");
        setup.Process.Emit("""{"event":"property-change","name":"time-pos","data":1000}""");
        setup.Process.Emit("""{"event":"property-change","name":"eof-reached","data":true}""");
        await Task.Delay(30);
        Assert.Equal(3, setup.Process.Commands.Count(command => command.Command == "loadfile"));
        Assert.Equal("ended", host.IptvMediaSnapshot().State);
    }

    [Fact]
    public async Task ManualEpisodeNavigationPreservesTheExistingFullscreenStateAndStopNeverAutoplays()
    {
        var setup = await EpisodeHostAsync("navigation.db");
        await using var host = setup.Host;
        var started = await host.StartIptvVodViewAsync("episode", setup.Episodes[0].Id, default);
        setup.Process.Emit("""{"event":"start-file"}""");
        setup.Process.Emit("""{"event":"property-change","name":"duration","data":1000}""");
        setup.Process.Emit("""{"event":"property-change","name":"seekable","data":true}""");
        setup.Process.Emit("""{"event":"playback-restart"}""");
        setup.Process.Emit("""{"event":"property-change","name":"time-pos","data":999}""");
        host.SetMpvFullscreen(started.SessionId!, true);

        var next = await host.NavigateIptvEpisodeAsync("next", default);
        Assert.True(next.Snapshot.Fullscreen);
        var previous = await host.NavigateIptvEpisodeAsync("previous", default);
        Assert.True(previous.Snapshot.Fullscreen);

        host.SetMpvFullscreen(previous.SessionId!, false);
        var windowed = await host.NavigateIptvEpisodeAsync("next", default);
        Assert.False(windowed.Snapshot.Fullscreen);
        setup.Process.Emit("""{"event":"start-file"}""");
        setup.Process.Emit("""{"event":"property-change","name":"duration","data":1000}""");
        setup.Process.Emit("""{"event":"property-change","name":"seekable","data":true}""");
        setup.Process.Emit("""{"event":"playback-restart"}""");
        setup.Process.Emit("""{"event":"property-change","name":"time-pos","data":999}""");
        var beforeStop = setup.Process.Commands.Count(command => command.Command == "loadfile");
        await host.ControlMpvAsync(windowed.SessionId!, "stop", null, default);
        await Task.Delay(30);
        Assert.Equal(beforeStop, setup.Process.Commands.Count(command => command.Command == "loadfile"));
    }

    private async Task<(HostEngine Host, FakeProcess Process, IReadOnlyList<IptvEpisodeSummary> Episodes)> EpisodeHostAsync(string database)
    {
        var protector = new Protector();
        var store = new IptvStore(Path.Combine(_root, database), protector); store.Initialize();
        var source = store.AddSource("Xtream", IptvSourceType.Xtream, "{\"version\":1,\"baseUrl\":\"https://provider.example\",\"username\":\"alice\",\"password\":\"secret\"}");
        store.ReplaceVodCatalog(source.Id, new XtreamVodImport([], [], [new XtreamSeries("500", "Series A", null, null, null, null, null, null, null, null)]));
        var series = Assert.Single(store.SearchSeries(source.Id, "", null, 0, 10).Items);
        var details = store.ReplaceSeriesEpisodes(series.Id, [
            new XtreamEpisode("301", 1, 2, "Episode 2", "mkv", "16:40", null, null),
            new XtreamEpisode("302", 1, 3, "Episode 3", "mkv", "16:40", null, null),
            new XtreamEpisode("401", 2, 1, "Episode 1", "mkv", "16:40", null, null),
        ]);
        var catalog = new IptvCatalogService(store, protector, new M3uParser());
        var process = new FakeProcess();
        var player = new MpvPlayer(new MpvRuntime(true, "mpv.exe", "test", "test", null), (nint)123, (_, _) => process);
        var host = new HostEngine(_ => catalog, value => MediaEngine.CreateDefault(value, GStreamerRuntime.UnavailableForTests()),
            new MpvRuntime(true, "mpv.exe", "test", "test", null), (_, _) => player);
        host.SetNativeVideoHost((nint)123); host.Start(); await host.ActivateAccountAsync(new string('d', 64));
        return (host, process, details.Episodes);
    }

    private static async Task WaitUntilAsync(Func<bool> predicate)
    {
        for (var attempt = 0; attempt < 50 && !predicate(); attempt++) await Task.Delay(10);
        Assert.True(predicate());
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
