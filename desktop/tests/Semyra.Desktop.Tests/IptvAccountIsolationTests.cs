using System.Text;
using Semyra.Desktop.Host;
using Semyra.Desktop.Iptv;
using Semyra.Desktop.Media;

namespace Semyra.Desktop.Tests;

public sealed class IptvAccountIsolationTests : IDisposable
{
    private readonly string _root = Path.Combine(Path.GetTempPath(), "semyra-account-tests", Guid.NewGuid().ToString("N"));
    private const string ProfileA = "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa";
    private const string ProfileB = "bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb";

    [Fact]
    public void PathsAreDistinctValidatedAndNeverAdoptLegacyDatabase()
    {
        var legacy = Path.Combine(_root, "Semyra", "Data", "semyra.db");
        Directory.CreateDirectory(Path.GetDirectoryName(legacy)!);
        File.WriteAllText(legacy, "legacy");

        var pathA = IptvPaths.DatabasePath(ProfileA, _root);
        var pathB = IptvPaths.DatabasePath(ProfileB, _root);

        Assert.NotEqual(pathA, pathB);
        Assert.Contains(Path.Combine("Profiles", ProfileA), pathA);
        Assert.Contains(Path.Combine("Profiles", ProfileB), pathB);
        Assert.NotEqual(legacy, pathA);
        Assert.NotEqual(legacy, pathB);
        Assert.Throws<ArgumentException>(() => IptvPaths.DatabasePath("../escape", _root));
        Assert.Throws<ArgumentException>(() => IptvPaths.DatabasePath(new string('A', 64), _root));
        Assert.Equal("legacy", File.ReadAllText(legacy));
    }

    [Fact]
    public async Task AccountActivationPhysicallyIsolatesAndRestoresCatalogs()
    {
        await using var engine = Engine();
        engine.Start();
        var contextA = await engine.ActivateAccountAsync(ProfileA);
        engine.AddIptvUrl("Source A", "https://a.invalid/list.m3u");
        var rotatedA = await engine.ActivateAccountAsync(ProfileA);
        Assert.NotEqual(contextA, rotatedA);
        Assert.Single(engine.ListIptvSources());

        var contextB = await engine.ActivateAccountAsync(ProfileB);
        Assert.NotEqual(rotatedA, contextB);
        Assert.Empty(engine.ListIptvSources());
        Assert.False(engine.IsCurrentAccountContext(rotatedA));
        engine.AddIptvUrl("Source B", "https://b.invalid/list.m3u");

        await engine.ActivateAccountAsync(ProfileA);
        Assert.Equal("Source A", Assert.Single(engine.ListIptvSources()).Name);
        await engine.ClearAccountAsync();
        await engine.ClearAccountAsync();
        Assert.Equal(["host.status", "host.authorize"], engine.Snapshot().Capabilities);
    }

    [Fact]
    public async Task AccountSwitchStopsLocalMediaAndClearsAuthorization()
    {
        var pipelines = new PipelineFactory();
        var media = new List<MediaEngine>();
        await using var engine = EngineWithMedia(() => new MediaEngine(
            Resolve,
            GStreamerRuntime.AvailableForTests(),
            new Provider(),
            pipelines));
        engine.Start();
        await engine.ActivateAccountAsync(ProfileA);
        var oldMedia = media.Single();
        Assert.True(engine.Authorize(Authorization()));
        await engine.StartIptvMediaAsync(1, default);

        await engine.ActivateAccountAsync(ProfileB);

        Assert.Equal("idle", oldMedia.Snapshot().State);
        Assert.True(pipelines.Pipelines[0].Stopped);
        Assert.False(engine.Snapshot().Authorization.Authorized);

        HostEngine EngineWithMedia(Func<MediaEngine> create) => new(
            profile => Catalog(profile),
            _ => { var created = create(); media.Add(created); return created; });
    }

    [Fact]
    public async Task AccountSwitchStopsPublicationAndCleansIngress()
    {
        var pipelines = new PipelineFactory();
        var publish = new PublishClient();
        await using var engine = new HostEngine(
            profile => Catalog(profile),
            _ => new MediaEngine(Resolve, GStreamerRuntime.AvailableForTests(), new Provider(), pipelines, publishClient: publish));
        engine.Start();
        await engine.ActivateAccountAsync(ProfileA);
        Assert.True(engine.Authorize(Authorization()));
        await engine.StartIptvPublishAsync(1, default);

        await engine.ClearAccountAsync();

        Assert.True(pipelines.Pipelines[0].Stopped);
        Assert.Single(publish.Stops);
        Assert.False(engine.Snapshot().Authorization.Authorized);
        Assert.DoesNotContain("livekit.publish", engine.Snapshot().Capabilities);
    }

    [Fact]
    public async Task AccountSwitchInvalidatesLocalViewTokenAndStopsPipeline()
    {
        var pipelines = new PipelineFactory();
        MediaEngine? oldMedia = null;
        await using var engine = new HostEngine(
            profile => Catalog(profile),
            _ => oldMedia = new MediaEngine(Resolve, GStreamerRuntime.AvailableForTests(), new Provider(), pipelines));
        engine.Start();
        await engine.ActivateAccountAsync(ProfileA);
        var started = await engine.StartIptvLocalViewAsync(1, default);
        var oldUrl = new Uri(new Uri("https://semyra.example/"), started.PlaybackUrl);

        await engine.ActivateAccountAsync(ProfileB);

        Assert.Equal("idle", oldMedia!.Snapshot().State);
        Assert.True(pipelines.Pipelines[0].Stopped);
        Assert.False(oldMedia.TryReadLocalPlayback(oldUrl, "GET", out _));
    }

    [Fact]
    public async Task AccountClearReturnsPendingTaskAndInvalidatesContextBeforeMediaStopCompletes()
    {
        var stopGate = new TaskCompletionSource<bool>(TaskCreationOptions.RunContinuationsAsynchronously);
        var pipelines = new PipelineFactory(stopGate);
        MediaEngine? media = null;
        await using var engine = new HostEngine(
            profile => Catalog(profile),
            _ => media = new MediaEngine(Resolve, GStreamerRuntime.AvailableForTests(), new Provider(), pipelines));
        engine.Start();
        var context = await engine.ActivateAccountAsync(ProfileA);
        var started = await engine.StartIptvLocalViewAsync(1, default);
        var oldUrl = new Uri(new Uri("https://semyra.example/"), started.PlaybackUrl);

        var clear = engine.ClearAccountAsync();

        Assert.False(clear.IsCompleted);
        Assert.False(engine.IsCurrentAccountContext(context));
        Assert.False(media!.TryReadLocalPlayback(oldUrl, "GET", out _));
        await pipelines.Pipelines[0].StopStarted.Task.WaitAsync(TimeSpan.FromSeconds(2));
        stopGate.SetResult(true);
        await clear;
        Assert.True(pipelines.Pipelines[0].Stopped);
    }

    [Fact]
    public async Task AccountSwitchWaitsAsynchronouslyForOldCleanupBeforeActivatingNewProfile()
    {
        var stopGate = new TaskCompletionSource<bool>(TaskCreationOptions.RunContinuationsAsynchronously);
        var pipelines = new PipelineFactory(stopGate);
        var mediaCreated = 0;
        await using var engine = new HostEngine(
            profile => Catalog(profile),
            _ => { mediaCreated++; return new MediaEngine(Resolve, GStreamerRuntime.AvailableForTests(), new Provider(), pipelines); });
        engine.Start();
        var contextA = await engine.ActivateAccountAsync(ProfileA);
        await engine.StartIptvMediaAsync(1, default);

        var switchAccount = engine.ActivateAccountAsync(ProfileB);

        Assert.False(switchAccount.IsCompleted);
        Assert.False(engine.IsCurrentAccountContext(contextA));
        Assert.Equal(1, mediaCreated);
        await pipelines.Pipelines[0].StopStarted.Task.WaitAsync(TimeSpan.FromSeconds(2));
        stopGate.SetResult(true);
        var contextB = await switchAccount;
        Assert.True(engine.IsCurrentAccountContext(contextB));
        Assert.Equal(2, mediaCreated);
    }

    private HostEngine Engine() => new(
        profile => Catalog(profile),
        catalog => MediaEngine.CreateDefault(catalog, GStreamerRuntime.Discover(Path.Combine(_root, "missing"), new MissingRunner())));

    private IptvCatalogService Catalog(string profile)
    {
        var protector = new Protector();
        return new IptvCatalogService(new IptvStore(IptvPaths.DatabasePath(profile, _root), protector), protector, new M3uParser(), new HttpClient());
    }

    private static IptvPlaybackChannel Resolve(long channelId) => new(
        channelId,
        1,
        "Canal " + channelId,
        "Live",
        new Uri("https://provider.example/private/live.ts"));

    private static HostAuthorization Authorization() => new(
        new string('a', 32) + "." + new string('b', 64),
        "ROOM2345",
        new string('c', 32),
        1,
        "media.publish",
        DateTimeOffset.UtcNow.AddMinutes(5));

    public void Dispose()
    {
        if (Directory.Exists(_root)) Directory.Delete(_root, true);
    }

    private sealed class Protector : ISecretProtector
    {
        public byte[] Protect(string value) => Encoding.UTF8.GetBytes(value);
        public string Unprotect(byte[] value) => Encoding.UTF8.GetString(value);
    }

    private sealed class MissingRunner : IGStreamerCommandRunner
    {
        public (int ExitCode, string Output) Run(string executable, IReadOnlyList<string> arguments) => (-1, string.Empty);
    }

    private sealed class Provider : IProviderStreamClient
    {
        public Task<Stream> OpenAsync(Uri streamUri, CancellationToken cancellationToken)
        {
            var bytes = new byte[188 * 4];
            for (var index = 0; index < 4; index++) bytes[index * 188] = 0x47;
            return Task.FromResult<Stream>(new MemoryStream(bytes, writable: false));
        }
        public void Dispose() { }
    }

    private sealed class PipelineFactory(TaskCompletionSource<bool>? stopGate = null) : IMediaPipelineFactory
    {
        public List<Pipeline> Pipelines { get; } = [];
        public IMediaPipeline Create() { var value = new Pipeline(stopGate); Pipelines.Add(value); return value; }
        public IMediaPipeline CreatePublish(Uri whipEndpoint) => Create();
        public IMediaPipeline CreateLocalView(string outputDirectory)
        {
            File.WriteAllText(Path.Combine(outputDirectory, "index.m3u8"), "#EXTM3U\n");
            return Create();
        }
    }

    private sealed class Pipeline(TaskCompletionSource<bool>? stopGate) : IMediaPipeline
    {
        public bool Stopped { get; private set; }
        public TaskCompletionSource<bool> StopStarted { get; } = new(TaskCreationOptions.RunContinuationsAsynchronously);
        public Task StartAsync(ReadOnlyMemory<byte> initialBuffer, CancellationToken cancellationToken) => Task.CompletedTask;
        public Task PumpAsync(Stream providerStream, CancellationToken cancellationToken) => Task.Delay(Timeout.InfiniteTimeSpan, cancellationToken);
        public async Task StopAsync()
        {
            Stopped = true;
            StopStarted.TrySetResult(true);
            if (stopGate is not null)
            {
                await stopGate.Task;
            }
        }
        public async ValueTask DisposeAsync() => await StopAsync();
    }

    private sealed class PublishClient : IDesktopPublishClient
    {
        public List<HostAuthorization> Stops { get; } = [];
        public Task<DesktopPublishLease> StartAsync(HostAuthorization authorization, CancellationToken cancellationToken) =>
            Task.FromResult(new DesktopPublishLease("INGRESS_TEST", new Uri("https://whip.example/test")));
        public Task StopAsync(HostAuthorization authorization, string ingressId, CancellationToken cancellationToken)
        {
            Stops.Add(authorization);
            return Task.CompletedTask;
        }
    }
}
