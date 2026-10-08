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

    private sealed class TestProtector : ISecretProtector
    {
        public byte[] Protect(string value) => Encoding.UTF8.GetBytes(Convert.ToBase64String(Encoding.UTF8.GetBytes(value)));
        public string Unprotect(byte[] value) => Encoding.UTF8.GetString(Convert.FromBase64String(Encoding.UTF8.GetString(value)));
    }
}
