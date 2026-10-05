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
    public void SnapshotExposesOnlyStatusCapability()
    {
        var engine = new HostEngine();
        engine.Start();

        var snapshot = engine.Snapshot();

        Assert.Equal("ready", snapshot.State);
        Assert.Equal(["host.status"], snapshot.Capabilities);
    }
}
