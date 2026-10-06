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
    public void StopTransitionsToStoppedAndIsIdempotent()
    {
        var engine = new HostEngine();
        engine.Start();

        engine.Stop();
        engine.Stop();

        Assert.Equal(HostEngineState.Stopped, engine.State);
    }

    [Fact]
    public void SnapshotExposesOnlyImplementedCapabilities()
    {
        var engine = new HostEngine();
        engine.Start();

        var snapshot = engine.Snapshot();

        Assert.Equal("ready", snapshot.State);
        Assert.Equal(["host.status", "host.authorize", "iptv.sources", "iptv.catalog"], snapshot.Capabilities);
        Assert.False(snapshot.Authorization.Authorized);
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
    public void ClearIsIdempotentAndKeepsEngineReady()
    {
        var engine = new HostEngine();
        engine.Start();
        engine.Authorize(Authorization("a", 1));

        engine.ClearAuthorization();
        engine.ClearAuthorization();

        Assert.Equal(HostEngineState.Ready, engine.State);
        Assert.False(engine.Snapshot().Authorization.Authorized);
    }

    [Fact]
    public void StopClearsAuthorization()
    {
        var engine = new HostEngine();
        engine.Start();
        engine.Authorize(Authorization("a", 1));

        engine.Stop();

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
