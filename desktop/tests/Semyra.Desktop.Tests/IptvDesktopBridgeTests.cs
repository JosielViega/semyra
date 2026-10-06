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
        var engine = Engine();
        engine.Start();
        const string location = "https://provider.example/user/password/list.m3u";
        var result = await DesktopBridge.TryHandleAsync(
            $$"""{"type":"semyra.desktop.iptv.sources.add","requestId":"add-1","sourceType":"m3u_url","name":"Minha lista","location":"{{location}}"}""",
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
        var engine = Engine();
        engine.Start();
        var result = await DesktopBridge.TryHandleAsync(
            """{"type":"semyra.desktop.iptv.sources.pick-file","requestId":"pick-1"}""",
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
    [InlineData("{\"type\":\"semyra.desktop.iptv.sources.add\",\"requestId\":\"x\",\"sourceType\":\"m3u_url\",\"name\":\"Fonte\",\"location\":\"file:///private.m3u\"}")]
    [InlineData("{\"type\":\"semyra.desktop.iptv.sources.remove\",\"requestId\":\"x\",\"sourceId\":0}")]
    [InlineData("{\"type\":\"semyra.desktop.iptv.channels.search\",\"requestId\":\"x\",\"sourceId\":1,\"query\":\"\",\"group\":null,\"offset\":0,\"limit\":101}")]
    [InlineData("{\"type\":\"semyra.desktop.iptv.sources.list\",\"requestId\":\"x\",\"extra\":true}")]
    public async Task InvalidIptvMessagesAreRejectedOrSafelyFailed(string json)
    {
        var engine = Engine();
        engine.Start();
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

    public void Dispose()
    {
        if (Directory.Exists(_directory)) Directory.Delete(_directory, true);
    }

    private HostEngine Engine()
    {
        Directory.CreateDirectory(_directory);
        var protector = new TestProtector();
        var catalog = new IptvCatalogService(
            new IptvStore(Path.Combine(_directory, "catalog.db"), protector),
            protector,
            new M3uParser(),
            new HttpClient());
        return new HostEngine(catalog);
    }

    private sealed class TestProtector : ISecretProtector
    {
        public byte[] Protect(string value) => Encoding.UTF8.GetBytes(Convert.ToBase64String(Encoding.UTF8.GetBytes(value)));
        public string Unprotect(byte[] value) => Encoding.UTF8.GetString(Convert.FromBase64String(Encoding.UTF8.GetString(value)));
    }
}
