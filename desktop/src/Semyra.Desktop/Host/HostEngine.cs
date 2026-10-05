namespace Semyra.Desktop.Host;

public sealed class HostEngine
{
    public HostEngineState State { get; private set; } = HostEngineState.Stopped;

    public void Start()
    {
        State = HostEngineState.Ready;
    }

    public void Stop()
    {
        State = HostEngineState.Stopped;
    }

    public HostEngineSnapshot Snapshot()
    {
        return HostEngineSnapshot.Create(State);
    }
}
