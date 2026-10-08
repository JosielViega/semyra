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
        Assert.Equal(
            ["host.status", "host.authorize"],
            host.GetProperty("capabilities").EnumerateArray().Select(value => value.GetString()));
        Assert.False(host.GetProperty("authorization").GetProperty("authorized").GetBoolean());
    }

    [Fact]
    public void ValidAuthorizationIsStoredWithoutEchoingToken()
    {
        var hostEngine = new HostEngine();
        hostEngine.Start();
        var token = new string('a', 32) + "." + new string('b', 64);
        var json = $$"""
            {"type":"semyra.desktop.host.authorize","requestId":"authorize-1","hostSessionToken":"{{token}}","roomCode":"ROOM2345","transmissionInstanceId":"{{new string('c', 32)}}","transmissionRevision":3,"permission":"media.publish","expiresAt":"2099-10-06T12:00:00.000Z"}
            """;

        Assert.True(DesktopBridge.TryHandle(json, hostEngine, out var response));
        Assert.DoesNotContain(token, response);
        using var result = JsonDocument.Parse(response);
        Assert.Equal("semyra.desktop.host.authorize-result", result.RootElement.GetProperty("type").GetString());
        Assert.True(result.RootElement.GetProperty("authorized").GetBoolean());
        Assert.Equal(3, result.RootElement.GetProperty("transmissionRevision").GetInt64());
        Assert.True(hostEngine.Snapshot().Authorization.Authorized);
        Assert.DoesNotContain(token, JsonSerializer.Serialize(hostEngine.Snapshot()));
    }

    [Fact]
    public void ClearIsIdempotentAndDoesNotStopHost()
    {
        var hostEngine = new HostEngine();
        hostEngine.Start();

        foreach (var requestId in new[] { "clear-1", "clear-2" })
        {
            Assert.True(DesktopBridge.TryHandle(
                $$"""{"type":"semyra.desktop.host.clear","requestId":"{{requestId}}"}""",
                hostEngine,
                out var response));
            Assert.Contains("semyra.desktop.host.clear-result", response);
        }

        Assert.Equal(HostEngineState.Ready, hostEngine.State);
        Assert.False(hostEngine.Snapshot().Authorization.Authorized);
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
    [InlineData("{\"type\":\"semyra.desktop.host.clear\",\"requestId\":\"ok\",\"extra\":true}")]
    [InlineData("{\"type\":\"semyra.desktop.host.authorize\",\"requestId\":\"ok\",\"hostSessionToken\":\"invalid\",\"roomCode\":\"ROOM2345\",\"transmissionInstanceId\":\"aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa\",\"transmissionRevision\":1,\"permission\":\"media.publish\",\"expiresAt\":\"2099-10-06T12:00:00Z\"}")]
    [InlineData("{\"type\":\"semyra.desktop.host.authorize\",\"requestId\":\"ok\",\"hostSessionToken\":\"aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa.bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb\",\"roomCode\":\"ROOM2345\",\"transmissionInstanceId\":\"aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa\",\"transmissionRevision\":1,\"permission\":\"admin\",\"expiresAt\":\"2099-10-06T12:00:00Z\"}")]
    [InlineData("{\"type\":\"semyra.desktop.host.authorize\",\"requestId\":\"ok\",\"hostSessionToken\":\"aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa.bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb\",\"roomCode\":\"ROOM2345\",\"transmissionInstanceId\":\"invalid\",\"transmissionRevision\":1,\"permission\":\"media.publish\",\"expiresAt\":\"2099-10-06T12:00:00Z\"}")]
    [InlineData("{\"type\":\"semyra.desktop.host.authorize\",\"requestId\":\"ok\",\"hostSessionToken\":\"aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa.bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb\",\"roomCode\":\"ROOM2345\",\"transmissionInstanceId\":\"aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa\",\"transmissionRevision\":0,\"permission\":\"media.publish\",\"expiresAt\":\"2099-10-06T12:00:00Z\"}")]
    [InlineData("{\"type\":\"semyra.desktop.host.authorize\",\"requestId\":\"ok\",\"hostSessionToken\":\"aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa.bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb\",\"roomCode\":\"ROOM2345\",\"transmissionInstanceId\":\"aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa\",\"transmissionRevision\":1,\"permission\":\"media.publish\",\"expiresAt\":\"2099-10-06T12:00:00Z\",\"extra\":true}")]
    public void InvalidOrUnknownMessageIsRejected(string json)
    {
        Assert.False(DesktopBridge.TryHandle(json, new HostEngine(), out var response));
        Assert.Equal(string.Empty, response);
    }

    [Fact]
    public void MediaProtocolAcceptsOnlyChannelIdBoundary()
    {
        var context = new string('e', 32);
        Assert.True(DesktopMessage.TryReadRequest(
            $$"""{"type":"semyra.desktop.iptv.media.start","requestId":"media-1","accountContextId":"{{context}}","channelId":42}""",
            out var start));
        Assert.Equal(42, start.Media!.ChannelId);
        Assert.True(DesktopMessage.TryReadRequest(
            $$"""{"type":"semyra.desktop.iptv.media.stop","requestId":"media-2","accountContextId":"{{context}}"}""",
            out _));
        Assert.True(DesktopMessage.TryReadRequest(
            $$"""{"type":"semyra.desktop.iptv.media.status","requestId":"media-3","accountContextId":"{{context}}"}""",
            out _));

        Assert.False(DesktopMessage.TryReadRequest(
            """{"type":"semyra.desktop.iptv.media.start","requestId":"media-4","channelId":42,"url":"https://private.example/live"}""",
            out _));
        Assert.False(DesktopMessage.TryReadRequest(
            """{"type":"semyra.desktop.iptv.media.stop","requestId":"media-5","extra":true}""",
            out _));
    }

    [Fact]
    public void AccountProtocolIsStrictAndProducesEphemeralFence()
    {
        var profile = new string('a', 64);
        Assert.True(DesktopMessage.TryReadRequest(
            $$"""{"type":"semyra.desktop.account.activate","requestId":"account-1","profileId":"{{profile}}"}""",
            out var activate));
        Assert.Equal(profile, activate.Account!.ProfileId);
        Assert.True(DesktopMessage.TryReadRequest(
            """{"type":"semyra.desktop.account.clear","requestId":"account-2"}""", out _));
        Assert.False(DesktopMessage.TryReadRequest(
            $$"""{"type":"semyra.desktop.account.activate","requestId":"account-3","profileId":"{{profile}}","extra":true}""", out _));
        Assert.False(DesktopMessage.TryReadRequest(
            """{"type":"semyra.desktop.account.activate","requestId":"account-4","profileId":"../bad"}""", out _));
    }
}
