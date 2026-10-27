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
        Array.AsReadOnly(["host.status", "host.authorize", "window.fullscreen", "iptv.sources", "iptv.catalog"]);

    public static HostEngineSnapshot Create(
        HostEngineState state,
        HostAuthorization? authorization,
        bool accountActive = false,
        bool whipAvailable = false,
        bool localViewAvailable = false,
        bool vodPublishAvailable = false)
    {
        IReadOnlyList<string> capabilities;
        if (!accountActive)
        {
            capabilities = Array.AsReadOnly(["host.status", "host.authorize", "window.fullscreen"]);
        }
        else
        {
            var available = new List<string>(SupportedCapabilities);
            if (localViewAvailable) available.Add("iptv.local-view");
            if (whipAvailable)
            {
                available.Add("media.whip");
                available.Add("livekit.publish");
                if (vodPublishAvailable) available.Add("livekit.publish-vod");
            }
            capabilities = available.AsReadOnly();
        }
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
