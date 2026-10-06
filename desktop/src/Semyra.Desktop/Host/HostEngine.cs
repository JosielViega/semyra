namespace Semyra.Desktop.Host;

public sealed class HostEngine
{
    public HostEngineState State { get; private set; } = HostEngineState.Stopped;
    private HostAuthorization? _authorization;

    public void Start()
    {
        State = HostEngineState.Ready;
    }

    public void Stop()
    {
        _authorization = null;
        State = HostEngineState.Stopped;
    }

    public bool Authorize(HostAuthorization authorization)
    {
        if (State != HostEngineState.Ready || authorization.ExpiresAt <= DateTimeOffset.UtcNow)
        {
            return false;
        }

        _authorization = authorization;
        return true;
    }

    public void ClearAuthorization()
    {
        _authorization = null;
    }

    public HostEngineSnapshot Snapshot()
    {
        return HostEngineSnapshot.Create(State, _authorization);
    }
}
