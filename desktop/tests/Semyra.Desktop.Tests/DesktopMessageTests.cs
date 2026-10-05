using System.Text.Json;
using Semyra.Desktop.Desktop;

namespace Semyra.Desktop.Tests;

public sealed class DesktopMessageTests
{
    [Fact]
    public void ValidPingProducesProtocolPong()
    {
        Assert.True(DesktopBridge.TryHandle(
            """{"type":"semyra.desktop.ping","requestId":"request-11a"}""",
            out var response));

        using var pong = JsonDocument.Parse(response);
        Assert.Equal("semyra.desktop.pong", pong.RootElement.GetProperty("type").GetString());
        Assert.Equal("request-11a", pong.RootElement.GetProperty("requestId").GetString());
        Assert.Equal(1, pong.RootElement.GetProperty("protocolVersion").GetInt32());
        Assert.True(pong.RootElement.GetProperty("desktop").GetBoolean());
        Assert.Equal("windows", pong.RootElement.GetProperty("platform").GetString());
        Assert.False(string.IsNullOrWhiteSpace(pong.RootElement.GetProperty("appVersion").GetString()));
    }

    [Theory]
    [InlineData("not-json")]
    [InlineData("{}")]
    [InlineData("{\"type\":\"unknown\",\"requestId\":\"request-11a\"}")]
    [InlineData("{\"type\":\"semyra.desktop.ping\"}")]
    [InlineData("{\"type\":\"semyra.desktop.ping\",\"requestId\":\"contains space\"}")]
    [InlineData("{\"type\":\"semyra.desktop.ping\",\"requestId\":\"ok\",\"extra\":true}")]
    public void InvalidOrUnknownMessageIsRejected(string json)
    {
        Assert.False(DesktopBridge.TryHandle(json, out var response));
        Assert.Equal(string.Empty, response);
    }
}
