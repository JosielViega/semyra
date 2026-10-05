using System.Reflection;
using System.Text.Json;
using Semyra.Desktop.Host;

namespace Semyra.Desktop.Desktop;

public static class DesktopBridge
{
    public static bool TryHandle(string json, HostEngine hostEngine, out string response)
    {
        response = string.Empty;
        if (!DesktopMessage.TryReadRequest(json, out var type, out var requestId))
        {
            return false;
        }

        response = type switch
        {
            DesktopMessage.PingType => CreatePong(requestId),
            DesktopMessage.HostStatusType => CreateHostStatus(requestId, hostEngine.Snapshot()),
            _ => string.Empty,
        };

        return response.Length > 0;
    }

    private static string CreatePong(string requestId)
    {
        var version = Assembly.GetEntryAssembly()?.GetName().Version?.ToString(3) ?? "0.0.0";
        return JsonSerializer.Serialize(new
        {
            type = DesktopMessage.PongType,
            requestId,
            protocolVersion = DesktopMessage.ProtocolVersion,
            desktop = true,
            platform = "windows",
            appVersion = version,
        });
    }

    private static string CreateHostStatus(string requestId, HostEngineSnapshot snapshot)
    {
        return JsonSerializer.Serialize(new
        {
            type = DesktopMessage.HostStatusResultType,
            requestId,
            protocolVersion = DesktopMessage.ProtocolVersion,
            host = new
            {
                state = snapshot.State,
                capabilities = snapshot.Capabilities,
            },
        });
    }
}
