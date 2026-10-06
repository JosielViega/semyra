using System.Collections.ObjectModel;

namespace Semyra.Desktop.Host;

public sealed record HostAuthorizationSnapshot(
    bool Authorized,
    string? Permission,
    string? TransmissionInstanceId,
    long? TransmissionRevision);

public sealed record HostEngineSnapshot(
    string State,
    IReadOnlyList<string> Capabilities,
    HostAuthorizationSnapshot Authorization)
{
    private static readonly ReadOnlyCollection<string> SupportedCapabilities =
        Array.AsReadOnly(["host.status", "host.authorize"]);

    public static HostEngineSnapshot Create(HostEngineState state, HostAuthorization? authorization)
    {
        return new HostEngineSnapshot(
            state == HostEngineState.Ready ? "ready" : "stopped",
            SupportedCapabilities,
            authorization is null
                ? new HostAuthorizationSnapshot(false, null, null, null)
                : new HostAuthorizationSnapshot(
                    true,
                    authorization.Permission,
                    authorization.TransmissionInstanceId,
                    authorization.TransmissionRevision));
    }
}
