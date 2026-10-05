using System.Text.Json;
using Semyra.Desktop.Desktop;
using Semyra.Desktop.Host;

namespace Semyra.Desktop.Tests;

public sealed class DesktopMessageTests
{
    [Fact]
    public void ValidPingProducesProtocolPong()
    {
        var hostEngine = new HostEngine();
        Assert.True(DesktopBridge.TryHandle(
            """{"type":"semyra.desktop.ping","requestId":"request-11a"}""",
            hostEngine,
            out var response));

        using var pong = JsonDocument.Parse(response);
        Assert.Equal("semyra.desktop.pong", pong.RootElement.GetProperty("type").GetString());
        Assert.Equal("request-11a", pong.RootElement.GetProperty("requestId").GetString());
        Assert.Equal(1, pong.RootElement.GetProperty("protocolVersion").GetInt32());
        Assert.True(pong.RootElement.GetProperty("desktop").GetBoolean());
        Assert.Equal("windows", pong.RootElement.GetProperty("platform").GetString());
        Assert.False(string.IsNullOrWhiteSpace(pong.RootElement.GetProperty("appVersion").GetString()));
    }

    [Fact]
    public void ValidHostStatusProducesReadySnapshot()
    {
        var hostEngine = new HostEngine();
        hostEngine.Start();

        Assert.True(DesktopBridge.TryHandle(
            """{"type":"semyra.desktop.host.status","requestId":"request-11b"}""",
            hostEngine,
            out var response));

        using var result = JsonDocument.Parse(response);
        Assert.Equal("semyra.desktop.host.status-result", result.RootElement.GetProperty("type").GetString());
        Assert.Equal("request-11b", result.RootElement.GetProperty("requestId").GetString());
        Assert.Equal(1, result.RootElement.GetProperty("protocolVersion").GetInt32());
        var host = result.RootElement.GetProperty("host");
        Assert.Equal("ready", host.GetProperty("state").GetString());
        Assert.Equal(["host.status"], host.GetProperty("capabilities").EnumerateArray().Select(value => value.GetString()));
    }

    [Theory]
    [InlineData("not-json")]
    [InlineData("{}")]
    [InlineData("{\"type\":\"unknown\",\"requestId\":\"request-11a\"}")]
    [InlineData("{\"type\":\"semyra.desktop.ping\"}")]
    [InlineData("{\"type\":\"semyra.desktop.ping\",\"requestId\":\"contains space\"}")]
    [InlineData("{\"type\":\"semyra.desktop.ping\",\"requestId\":\"ok\",\"extra\":true}")]
    [InlineData("{\"type\":\"semyra.desktop.host.status\",\"requestId\":\"ok\",\"extra\":true}")]
    [InlineData("{\"type\":\"semyra.desktop.host.status\",\"requestId\":42}")]
    [InlineData("{\"type\":\"semyra.desktop.host.status\",\"requestId\":\"ok\",\"requestId\":\"duplicate\"}")]
    public void InvalidOrUnknownMessageIsRejected(string json)
    {
        Assert.False(DesktopBridge.TryHandle(json, new HostEngine(), out var response));
        Assert.Equal(string.Empty, response);
    }
}
