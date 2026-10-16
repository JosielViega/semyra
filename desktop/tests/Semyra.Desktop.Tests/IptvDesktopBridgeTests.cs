using System.Text;
using System.Text.Json;
using Semyra.Desktop.Desktop;
using Semyra.Desktop.Host;
using Semyra.Desktop.Iptv;

namespace Semyra.Desktop.Tests;

public sealed class IptvDesktopBridgeTests : IDisposable
{
    private readonly string _directory = Path.Combine(Path.GetTempPath(), "semyra-bridge-tests", Guid.NewGuid().ToString("N"));

    [Fact]
    public async Task UrlAddReturnsSafeDtoWithoutEchoingLocation()
    {
        var engine = await EngineAsync();
        engine.Start();
        const string location = "https://provider.example/user/password/list.m3u";
        var result = await DesktopBridge.TryHandleAsync(
            $$"""{"type":"semyra.desktop.iptv.sources.add","requestId":"add-1","accountContextId":"{{engine.CurrentAccountContextId}}","sourceType":"m3u_url","name":"Minha lista","location":"{{location}}"}""",
            engine,
            () => null);

        Assert.True(result.Handled);
        Assert.DoesNotContain(location, result.Response);
        Assert.DoesNotContain("location", result.Response, StringComparison.OrdinalIgnoreCase);
        using var json = JsonDocument.Parse(result.Response);
        Assert.True(json.RootElement.GetProperty("ok").GetBoolean());
        Assert.Equal("Minha lista", json.RootElement.GetProperty("source").GetProperty("name").GetString());
    }

    [Fact]
    public async Task FilePathCanOnlyComeFromNativePicker()
    {
        Directory.CreateDirectory(_directory);
        var playlist = Path.Combine(_directory, "local.m3u");
        await File.WriteAllTextAsync(playlist, "#EXTM3U\n");
        var engine = await EngineAsync();
        engine.Start();
        var result = await DesktopBridge.TryHandleAsync(
            $$"""{"type":"semyra.desktop.iptv.sources.pick-file","requestId":"pick-1","accountContextId":"{{engine.CurrentAccountContextId}}"}""",
            engine,
            () => playlist);

        Assert.True(result.Handled);
        Assert.DoesNotContain(playlist, result.Response);
        Assert.False(JsonDocument.Parse(result.Response).RootElement.GetProperty("cancelled").GetBoolean());
        Assert.False(DesktopMessage.TryReadRequest(
            """{"type":"semyra.desktop.iptv.sources.pick-file","requestId":"pick-2","path":"C:\\private.m3u"}""",
            out _));
    }

    [Theory]
    [InlineData("{\"type\":\"semyra.desktop.iptv.sources.add\",\"requestId\":\"x\",\"accountContextId\":\"eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee\",\"sourceType\":\"m3u_url\",\"name\":\"Fonte\",\"location\":\"file:///private.m3u\"}")]
    [InlineData("{\"type\":\"semyra.desktop.iptv.sources.remove\",\"requestId\":\"x\",\"sourceId\":0}")]
    [InlineData("{\"type\":\"semyra.desktop.iptv.channels.search\",\"requestId\":\"x\",\"sourceId\":1,\"query\":\"\",\"group\":null,\"offset\":0,\"limit\":101}")]
    [InlineData("{\"type\":\"semyra.desktop.iptv.sources.list\",\"requestId\":\"x\",\"extra\":true}")]
    public async Task InvalidIptvMessagesAreRejectedOrSafelyFailed(string json)
    {
        var engine = await EngineAsync();
        engine.Start();
        json = json.Replace(new string('e', 32), engine.CurrentAccountContextId, StringComparison.Ordinal);
        var result = await DesktopBridge.TryHandleAsync(json, engine, () => null);
        if (json.Contains("file:///", StringComparison.Ordinal))
        {
            Assert.True(result.Handled);
            Assert.Contains("HTTP", result.Response);
        }
        else
        {
            Assert.False(result.Handled);
        }
    }

    [Fact]
    public async Task MediaStatusAndRuntimeErrorsExposeOnlySafeCodes()
    {
        var engine = await EngineAsync();
        engine.Start();
        var status = await DesktopBridge.TryHandleAsync(
            $$"""{"type":"semyra.desktop.iptv.media.status","requestId":"media-status","accountContextId":"{{engine.CurrentAccountContextId}}"}""",
            engine,
            () => null);
        var unavailable = await DesktopBridge.TryHandleAsync(
            $$"""{"type":"semyra.desktop.iptv.media.start","requestId":"media-start","accountContextId":"{{engine.CurrentAccountContextId}}","channelId":1}""",
            engine,
            () => null);

        Assert.True(status.Handled);
        Assert.Contains("\"state\":\"idle\"", status.Response);
        Assert.True(unavailable.Handled);
        Assert.Contains("media_runtime_unavailable", unavailable.Response);
        Assert.DoesNotContain("http", unavailable.Response, StringComparison.OrdinalIgnoreCase);
        Assert.DoesNotContain("location", unavailable.Response, StringComparison.OrdinalIgnoreCase);
    }

    [Fact]
    public async Task OldOrWrongAccountContextCannotOperateOnCurrentCatalog()
    {
        var engine = await EngineAsync();
        var oldContext = engine.CurrentAccountContextId!;
        var currentContext = await engine.ActivateAccountAsync(new string('a', 64));

        var stale = await DesktopBridge.TryHandleAsync(
            $$"""{"type":"semyra.desktop.iptv.sources.list","requestId":"stale","accountContextId":"{{oldContext}}"}""",
            engine,
            () => null);
        var wrong = await DesktopBridge.TryHandleAsync(
            """{"type":"semyra.desktop.iptv.sources.list","requestId":"wrong","accountContextId":"ffffffffffffffffffffffffffffffff"}""",
            engine,
            () => null);
        var staleFavorite = await DesktopBridge.TryHandleAsync(
            $$"""{"type":"semyra.desktop.iptv.favorite.set","requestId":"stale-favorite","accountContextId":"{{oldContext}}","channelId":1,"favorite":true}""",
            engine,
            () => null);
        var staleRecentRemove = await DesktopBridge.TryHandleAsync(
            $$"""{"type":"semyra.desktop.iptv.recent.remove","requestId":"stale-remove","accountContextId":"{{oldContext}}","sourceId":1,"recentId":1}""",
            engine,
            () => null);
        var staleRecentsClear = await DesktopBridge.TryHandleAsync(
            $$"""{"type":"semyra.desktop.iptv.recents.clear","requestId":"stale-clear","accountContextId":"{{oldContext}}","sourceId":1}""",
            engine,
            () => null);
        var current = await DesktopBridge.TryHandleAsync(
            $$"""{"type":"semyra.desktop.iptv.sources.list","requestId":"current","accountContextId":"{{currentContext}}"}""",
            engine,
            () => null);

        Assert.True(stale.Handled);
        Assert.Contains("account_context_changed", stale.Response);
        Assert.True(wrong.Handled);
        Assert.Contains("account_context_changed", wrong.Response);
        Assert.True(current.Handled);
        Assert.Contains("\"ok\":true", current.Response);
        Assert.True(staleFavorite.Handled);
        Assert.Contains("account_context_changed", staleFavorite.Response);
        Assert.Contains("account_context_changed", staleRecentRemove.Response);
        Assert.Contains("account_context_changed", staleRecentsClear.Response);
    }

    [Fact]
    public async Task FavoriteAndRecentCommandsReturnTheirExactResultTypesAndControlledErrors()
    {
        var setup = await EngineWithChannelAsync();
        await using var engine = setup.Engine;
        var context = engine.CurrentAccountContextId;

        var emptyFavorites = await DesktopBridge.TryHandleAsync(
            $$"""{"type":"semyra.desktop.iptv.favorites.list","requestId":"favorites-empty","accountContextId":"{{context}}","sourceId":{{setup.SourceId}},"offset":0,"limit":50}""",
            engine,
            () => null);
        AssertResult(emptyFavorites, DesktopMessage.IptvFavoritesListResultType, true);
        Assert.Empty(JsonDocument.Parse(emptyFavorites.Response).RootElement.GetProperty("channels").EnumerateArray());

        var favorite = await DesktopBridge.TryHandleAsync(
            $$"""{"type":"semyra.desktop.iptv.favorite.set","requestId":"favorite-true","accountContextId":"{{context}}","channelId":{{setup.ChannelId}},"favorite":true}""",
            engine,
            () => null);
        AssertResult(favorite, DesktopMessage.IptvFavoriteSetResultType, true);
        Assert.True(JsonDocument.Parse(favorite.Response).RootElement.GetProperty("favorite").GetBoolean());

        var favorites = await DesktopBridge.TryHandleAsync(
            $$"""{"type":"semyra.desktop.iptv.favorites.list","requestId":"favorites-one","accountContextId":"{{context}}","sourceId":{{setup.SourceId}},"offset":0,"limit":50}""",
            engine,
            () => null);
        AssertResult(favorites, DesktopMessage.IptvFavoritesListResultType, true);
        Assert.Single(JsonDocument.Parse(favorites.Response).RootElement.GetProperty("channels").EnumerateArray());

        var recents = await DesktopBridge.TryHandleAsync(
            $$"""{"type":"semyra.desktop.iptv.recents.list","requestId":"recents-empty","accountContextId":"{{context}}","sourceId":{{setup.SourceId}}}""",
            engine,
            () => null);
        AssertResult(recents, DesktopMessage.IptvRecentsListResultType, true);
        var recentItems = JsonDocument.Parse(recents.Response).RootElement.GetProperty("channels").EnumerateArray().ToArray();
        Assert.Single(recentItems);
        Assert.Equal(setup.RecentId, recentItems[0].GetProperty("recentId").GetInt64());

        var removeRecent = await DesktopBridge.TryHandleAsync(
            $$"""{"type":"semyra.desktop.iptv.recent.remove","requestId":"recent-remove","accountContextId":"{{context}}","sourceId":{{setup.SourceId}},"recentId":{{setup.RecentId}}}""",
            engine,
            () => null);
        AssertResult(removeRecent, DesktopMessage.IptvRecentRemoveResultType, true);
        Assert.True(JsonDocument.Parse(removeRecent.Response).RootElement.GetProperty("removed").GetBoolean());

        setup.Store.RecordRecent(setup.ChannelId);

        var clearRecents = await DesktopBridge.TryHandleAsync(
            $$"""{"type":"semyra.desktop.iptv.recents.clear","requestId":"recents-clear","accountContextId":"{{context}}","sourceId":{{setup.SourceId}}}""",
            engine,
            () => null);
        AssertResult(clearRecents, DesktopMessage.IptvRecentsClearResultType, true);
        Assert.Equal(1, JsonDocument.Parse(clearRecents.Response).RootElement.GetProperty("removedCount").GetInt32());

        var unfavorite = await DesktopBridge.TryHandleAsync(
            $$"""{"type":"semyra.desktop.iptv.favorite.set","requestId":"favorite-false","accountContextId":"{{context}}","channelId":{{setup.ChannelId}},"favorite":false}""",
            engine,
            () => null);
        AssertResult(unfavorite, DesktopMessage.IptvFavoriteSetResultType, true);
        Assert.False(JsonDocument.Parse(unfavorite.Response).RootElement.GetProperty("favorite").GetBoolean());

        var controlledError = await DesktopBridge.TryHandleAsync(
            $$"""{"type":"semyra.desktop.iptv.favorite.set","requestId":"favorite-missing","accountContextId":"{{context}}","channelId":999999,"favorite":true}""",
            engine,
            () => null);
        AssertResult(controlledError, DesktopMessage.IptvFavoriteSetResultType, false);
    }

    public void Dispose()
    {
        if (Directory.Exists(_directory)) Directory.Delete(_directory, true);
    }

    private async Task<HostEngine> EngineAsync()
    {
        Directory.CreateDirectory(_directory);
        var protector = new TestProtector();
        var catalog = new IptvCatalogService(
            new IptvStore(Path.Combine(_directory, "catalog.db"), protector),
            protector,
            new M3uParser(),
            new HttpClient());
        var engine = new HostEngine(catalog);
        engine.Start();
        await engine.ActivateAccountAsync(new string('a', 64));
        return engine;
    }

    private async Task<(HostEngine Engine, IptvStore Store, long SourceId, long ChannelId, long RecentId)> EngineWithChannelAsync()
    {
        Directory.CreateDirectory(_directory);
        var protector = new TestProtector();
        var store = new IptvStore(Path.Combine(_directory, "favorite-catalog.db"), protector);
        store.Initialize();
        var source = store.AddSource("Fonte", IptvSourceType.M3uUrl, "https://provider.example/list.m3u");
        await store.ReplaceChannelsAsync(source.Id, OneChannel(), default);
        var channel = Assert.Single(store.SearchChannels(source.Id, "", null, 0, 50).Channels);
        store.RecordRecent(channel.Id);
        var recentId = Assert.Single(store.ListRecents(source.Id)).RecentId;
        var catalog = new IptvCatalogService(store, protector, new M3uParser(), new HttpClient());
        var engine = new HostEngine(catalog);
        engine.Start();
        await engine.ActivateAccountAsync(new string('a', 64));
        return (engine, store, source.Id, channel.Id, recentId);
    }

    private static async IAsyncEnumerable<ParsedM3uChannel> OneChannel()
    {
        yield return new ParsedM3uChannel("Canal", "https://provider.example/live", "canal-1", null, "Live");
        await Task.Yield();
    }

    private static void AssertResult((bool Handled, string Response) result, string type, bool ok)
    {
        Assert.True(result.Handled);
        using var json = JsonDocument.Parse(result.Response);
        Assert.Equal(type, json.RootElement.GetProperty("type").GetString());
        Assert.Equal(ok, json.RootElement.GetProperty("ok").GetBoolean());
    }

    private sealed class TestProtector : ISecretProtector
    {
        public byte[] Protect(string value) => Encoding.UTF8.GetBytes(Convert.ToBase64String(Encoding.UTF8.GetBytes(value)));
        public string Unprotect(byte[] value) => Encoding.UTF8.GetString(Convert.FromBase64String(Encoding.UTF8.GetString(value)));
    }
}
