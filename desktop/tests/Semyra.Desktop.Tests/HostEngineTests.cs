using Semyra.Desktop.Host;

namespace Semyra.Desktop.Tests;

public sealed class HostEngineTests
{
    [Fact]
    public void StartsStopped()
    {
        Assert.Equal(HostEngineState.Stopped, new HostEngine().State);
    }

    [Fact]
    public void StartTransitionsToReadyAndIsIdempotent()
    {
        var engine = new HostEngine();

        engine.Start();
        engine.Start();

        Assert.Equal(HostEngineState.Ready, engine.State);
    }

    [Fact]
    public async Task StopTransitionsToStoppedAndIsIdempotent()
    {
        var engine = new HostEngine();
        engine.Start();

        await engine.StopAsync();
        await engine.StopAsync();

        Assert.Equal(HostEngineState.Stopped, engine.State);
    }

    [Fact]
    public void SnapshotExposesOnlyImplementedCapabilities()
    {
        var engine = new HostEngine();
        engine.Start();

        var snapshot = engine.Snapshot();

        Assert.Equal("ready", snapshot.State);
        Assert.Equal(["host.status", "host.authorize"], snapshot.Capabilities);
        Assert.False(snapshot.Authorization.Authorized);
    }

    [Fact]
    public void LocalViewAndWhipCapabilitiesRemainIndependent()
    {
        var localOnly = HostEngineSnapshot.Create(
            HostEngineState.Ready, null, accountActive: true, mediaAvailable: true,
            whipAvailable: false, localViewAvailable: true);
        var publishWithoutHls = HostEngineSnapshot.Create(
            HostEngineState.Ready, null, accountActive: true, mediaAvailable: true,
            whipAvailable: true, localViewAvailable: false);

        Assert.Contains("iptv.local-view", localOnly.Capabilities);
        Assert.DoesNotContain("livekit.publish", localOnly.Capabilities);
        Assert.DoesNotContain("iptv.local-view", publishWithoutHls.Capabilities);
        Assert.Contains("livekit.publish", publishWithoutHls.Capabilities);
    }

    [Fact]
    public void AuthorizationStaysInMemoryAndReplacementIsSafeInSnapshot()
    {
        var engine = new HostEngine();
        engine.Start();
        var first = Authorization("a", 1);
        var replacement = Authorization("b", 2);

        Assert.True(engine.Authorize(first));
        Assert.True(engine.Authorize(replacement));

        var snapshot = engine.Snapshot();
        Assert.True(snapshot.Authorization.Authorized);
        Assert.Equal(replacement.TransmissionInstanceId, snapshot.Authorization.TransmissionInstanceId);
        Assert.Equal(2, snapshot.Authorization.TransmissionRevision);
        Assert.DoesNotContain(replacement.Token, System.Text.Json.JsonSerializer.Serialize(snapshot));
    }

    [Fact]
    public async Task ClearIsIdempotentAndKeepsEngineReady()
    {
        var engine = new HostEngine();
        engine.Start();
        engine.Authorize(Authorization("a", 1));

        await engine.ClearAuthorizationAsync();
        await engine.ClearAuthorizationAsync();

        Assert.Equal(HostEngineState.Ready, engine.State);
        Assert.False(engine.Snapshot().Authorization.Authorized);
    }

    [Fact]
    public async Task StopClearsAuthorization()
    {
        var engine = new HostEngine();
        engine.Start();
        engine.Authorize(Authorization("a", 1));

        await engine.StopAsync();

        Assert.Equal(HostEngineState.Stopped, engine.State);
        Assert.False(engine.Snapshot().Authorization.Authorized);
    }

    private static HostAuthorization Authorization(string instanceCharacter, long revision)
    {
        return new HostAuthorization(
            new string('c', 32) + "." + new string('d', 64),
            "ROOM2345",
            new string(instanceCharacter[0], 32),
            revision,
            "media.publish",
            DateTimeOffset.UtcNow.AddMinutes(10));
    }
}
