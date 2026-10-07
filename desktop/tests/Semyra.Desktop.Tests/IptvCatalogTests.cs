using System.Text;
using System.Text.Json;
using System.Net;
using System.Net.Http;
using Microsoft.Data.Sqlite;
using Semyra.Desktop.Iptv;

namespace Semyra.Desktop.Tests;

public sealed class IptvCatalogTests : IDisposable
{
    private readonly string _directory = Path.Combine(Path.GetTempPath(), "semyra-iptv-tests", Guid.NewGuid().ToString("N"));
    private readonly TestProtector _protector = new();

    [Fact]
    public async Task ParserHandlesBomMetadataBlankLinesAndMalformedEntries()
    {
        await using var stream = File.OpenRead(Path.Combine(AppContext.BaseDirectory, "Fixtures", "sample.m3u"));
        var channels = await ReadAll(new M3uParser().ParseAsync(stream));

        Assert.Equal(3, channels.Count);
        Assert.Equal("Notícias Um", channels[0].Name);
        Assert.Equal("news.one", channels[0].TvgId);
        Assert.Equal("Notícias", channels[0].Group);
        Assert.Equal("Esporte Um", channels[1].Name);
    }

    [Fact]
    public async Task LocalCatalogPersistsAcrossStoreRestartWithoutPlaintextLocations()
    {
        var database = DatabasePath();
        var store = new IptvStore(database, _protector);
        store.Initialize();
        var source = store.AddSource("Fixture", IptvSourceType.M3uFile, @"C:\private\provider.m3u");
        await store.ReplaceChannelsAsync(source.Id, Channels(
            new ParsedM3uChannel("Canal Seguro", "https://secret.example/user/password/stream", "id-1", null, "Grupo")), default);

        var reopened = new IptvStore(database, _protector);
        reopened.Initialize();
        var sources = reopened.ListSources();
        var result = reopened.SearchChannels(source.Id, "Canal", "Grupo", 0, 100);

        Assert.Single(sources);
        Assert.Equal(1, sources[0].ChannelCount);
        Assert.Single(result.Channels);
        var bytes = await File.ReadAllBytesAsync(database);
        var databaseText = Encoding.UTF8.GetString(bytes);
        Assert.DoesNotContain("provider.m3u", databaseText);
        Assert.DoesNotContain("secret.example", databaseText);
        Assert.DoesNotContain("streamUrl", JsonSerializer.Serialize(result));
        Assert.DoesNotContain("location", JsonSerializer.Serialize(sources), StringComparison.OrdinalIgnoreCase);
    }

    [Fact]
    public async Task FailedReplacementKeepsPreviousCatalog()
    {
        var store = new IptvStore(DatabasePath(), _protector);
        store.Initialize();
        var source = store.AddSource("Atomic", IptvSourceType.M3uUrl, "https://source.example/list.m3u");
        await store.ReplaceChannelsAsync(source.Id, Channels(
            new ParsedM3uChannel("Anterior", "https://stream.example/old", null, null, "Grupo")), default);

        await Assert.ThrowsAsync<IOException>(() => store.ReplaceChannelsAsync(source.Id, FailingChannels(), default));

        var result = store.SearchChannels(source.Id, string.Empty, null, 0, 100);
        Assert.Single(result.Channels);
        Assert.Equal("Anterior", result.Channels[0].Name);
    }

    [Fact]
    public void DpapiRoundTripDoesNotReturnPlaintextBytes()
    {
        var protector = new DpapiSecretProtector();
        const string secret = "https://provider.example/account/password/list.m3u";
        var protectedValue = protector.Protect(secret);

        Assert.Equal(secret, protector.Unprotect(protectedValue));
        Assert.DoesNotContain(secret, Encoding.UTF8.GetString(protectedValue));
    }

    [Fact]
    public async Task SearchUsesGroupsAndEnforcesBoundedPagination()
    {
        var store = new IptvStore(DatabasePath(), _protector);
        store.Initialize();
        var source = store.AddSource("Busca", IptvSourceType.M3uUrl, "https://source.example/list.m3u");
        await store.ReplaceChannelsAsync(source.Id, Channels(
            new ParsedM3uChannel("Alpha", "https://stream.example/a", null, null, "Grupo A"),
            new ParsedM3uChannel("Beta", "https://stream.example/b", null, null, "Grupo B")), default);

        Assert.Equal(["Grupo A", "Grupo B"], store.GetGroups(source.Id));
        var page = store.SearchChannels(source.Id, "a", null, 0, 1);
        Assert.Single(page.Channels);
        Assert.True(page.HasMore);
    }

    [Fact]
    public async Task UrlSourceRefreshesByStreamingAndCrudRemainsSourceScoped()
    {
        var store = new IptvStore(DatabasePath(), _protector);
        var handler = new StaticHandler("#EXTM3U\n#EXTINF:-1 group-title=\"Live\",Canal\nhttp://stream.example/live\n");
        using var client = new HttpClient(handler);
        using var catalog = new IptvCatalogService(store, _protector, new M3uParser(), client);
        catalog.Initialize();

        Assert.Throws<IptvValidationException>(() => catalog.AddUrlSource("Inválida", "file:///private.m3u"));
        var first = catalog.AddUrlSource("Remota", "http://provider.example/list.m3u");
        var second = catalog.AddUrlSource("Outra", "https://provider.example/other.m3u");
        var refreshed = await catalog.RefreshAsync(first.Id, default);

        Assert.Equal("ready", refreshed.LastRefreshStatus);
        Assert.Equal(1, refreshed.ChannelCount);
        Assert.Equal(["Live"], catalog.GetGroups(first.Id));
        Assert.True(catalog.RemoveSource(first.Id));
        Assert.Single(catalog.ListSources());
        Assert.Equal(second.Id, catalog.ListSources()[0].Id);
    }

    [Fact]
    public async Task MissingFileRefreshReportsSafeErrorAndPreservesCatalog()
    {
        Directory.CreateDirectory(_directory);
        var playlist = Path.Combine(_directory, "temporary.m3u");
        await File.WriteAllTextAsync(playlist, "#EXTM3U\n#EXTINF:-1,Anterior\nhttp://stream.example/old\n");
        var store = new IptvStore(DatabasePath(), _protector);
        using var catalog = new IptvCatalogService(store, _protector, new M3uParser(), new HttpClient());
        catalog.Initialize();
        var source = catalog.AddFileSource(playlist);
        await catalog.RefreshAsync(source.Id, default);
        File.Delete(playlist);

        var exception = await Assert.ThrowsAsync<IptvRefreshException>(() => catalog.RefreshAsync(source.Id, default));
        var preserved = catalog.ListSources().Single();
        Assert.Equal("O arquivo selecionado não está mais disponível.", exception.Message);
        Assert.Equal("error", preserved.LastRefreshStatus);
        Assert.Equal(1, preserved.ChannelCount);
        Assert.DoesNotContain(_directory, exception.Message);
    }

    [Fact]
    public async Task PlaybackResolutionStaysNativeAndRejectsUnavailableChannels()
    {
        var database = DatabasePath();
        var store = new IptvStore(database, _protector);
        store.Initialize();
        var source = store.AddSource("Playback", IptvSourceType.M3uUrl, "https://provider.example/list.m3u");
        const string streamUrl = "https://provider.example/private/live.ts";
        await store.ReplaceChannelsAsync(source.Id, Channels(
            new ParsedM3uChannel("Canal", streamUrl, null, null, "Live")), default);
        var channelId = store.SearchChannels(source.Id, string.Empty, null, 0, 10).Channels.Single().Id;

        var resolved = store.ResolveChannelForPlayback(channelId);

        Assert.Equal(channelId, resolved.Id);
        Assert.Equal(source.Id, resolved.SourceId);
        Assert.Equal(streamUrl, resolved.StreamUri.AbsoluteUri);
        Assert.Throws<IptvPlaybackException>(() => store.ResolveChannelForPlayback(channelId + 1000));

        var connectionString = new SqliteConnectionStringBuilder { DataSource = database, Pooling = false }.ToString();
        using (var connection = new SqliteConnection(connectionString))
        {
            connection.Open();
            using var command = connection.CreateCommand();
            command.CommandText = "UPDATE iptv_sources SET enabled = 0 WHERE id = $id";
            command.Parameters.AddWithValue("$id", source.Id);
            command.ExecuteNonQuery();
        }
        var unavailable = Assert.Throws<IptvPlaybackException>(() => store.ResolveChannelForPlayback(channelId));
        Assert.Equal("source_unavailable", unavailable.Code);
    }

    public void Dispose()
    {
        if (Directory.Exists(_directory))
        {
            Directory.Delete(_directory, recursive: true);
        }
    }

    private string DatabasePath()
    {
        Directory.CreateDirectory(_directory);
        return Path.Combine(_directory, "semyra.db");
    }

    private static async Task<List<ParsedM3uChannel>> ReadAll(IAsyncEnumerable<ParsedM3uChannel> source)
    {
        var result = new List<ParsedM3uChannel>();
        await foreach (var item in source) result.Add(item);
        return result;
    }

    private static async IAsyncEnumerable<ParsedM3uChannel> Channels(params ParsedM3uChannel[] channels)
    {
        foreach (var channel in channels)
        {
            await Task.Yield();
            yield return channel;
        }
    }

    private static async IAsyncEnumerable<ParsedM3uChannel> FailingChannels()
    {
        await Task.Yield();
        yield return new ParsedM3uChannel("Novo", "https://stream.example/new", null, null, null);
        throw new IOException("synthetic failure");
    }

    private sealed class TestProtector : ISecretProtector
    {
        public byte[] Protect(string value) => Encoding.UTF8.GetBytes(Convert.ToBase64String(Encoding.UTF8.GetBytes(value)));
        public string Unprotect(byte[] value) => Encoding.UTF8.GetString(Convert.FromBase64String(Encoding.UTF8.GetString(value)));
    }

    private sealed class StaticHandler(string content) : HttpMessageHandler
    {
        protected override Task<HttpResponseMessage> SendAsync(HttpRequestMessage request, CancellationToken cancellationToken)
        {
            var stream = new MemoryStream(Encoding.UTF8.GetBytes(content), writable: false);
            return Task.FromResult(new HttpResponseMessage(HttpStatusCode.OK)
            {
                Content = new StreamContent(stream),
                RequestMessage = request,
            });
        }
    }
}
