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
        Array.AsReadOnly(["host.status", "host.authorize", "iptv.sources", "iptv.catalog"]);

    public static HostEngineSnapshot Create(HostEngineState state, HostAuthorization? authorization, bool accountActive = false, bool mediaAvailable = false, bool whipAvailable = false)
    {
        var capabilities = !accountActive
            ? Array.AsReadOnly(["host.status", "host.authorize"])
            : whipAvailable
            ? Array.AsReadOnly(["host.status", "host.authorize", "iptv.sources", "iptv.catalog", "iptv.play", "media.whip", "livekit.publish"])
            : mediaAvailable
            ? Array.AsReadOnly(["host.status", "host.authorize", "iptv.sources", "iptv.catalog", "iptv.play"])
            : SupportedCapabilities;
        return new HostEngineSnapshot(
            state == HostEngineState.Ready ? "ready" : "stopped",
            capabilities,
            authorization is null
                ? new HostAuthorizationSnapshot(false, null, null, null)
                : new HostAuthorizationSnapshot(
                    true,
                    authorization.Permission,
                    authorization.TransmissionInstanceId,
                    authorization.TransmissionRevision));
    }
}
