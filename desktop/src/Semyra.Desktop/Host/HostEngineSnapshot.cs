using System.Collections.ObjectModel;

namespace Semyra.Desktop.Host;

public sealed record HostEngineSnapshot(string State, IReadOnlyList<string> Capabilities)
{
    private static readonly ReadOnlyCollection<string> SupportedCapabilities =
        Array.AsReadOnly(["host.status"]);

    public static HostEngineSnapshot Create(HostEngineState state)
    {
        return new HostEngineSnapshot(
            state == HostEngineState.Ready ? "ready" : "stopped",
            SupportedCapabilities);
    }
}
