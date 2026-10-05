using System.Reflection;
using System.Text.Json;

namespace Semyra.Desktop.Desktop;

public static class DesktopBridge
{
    public static bool TryHandle(string json, out string response)
    {
        response = string.Empty;
        if (!DesktopMessage.TryReadPing(json, out var requestId))
        {
            return false;
        }

        var version = Assembly.GetEntryAssembly()?.GetName().Version?.ToString(3) ?? "0.0.0";
        response = JsonSerializer.Serialize(new
        {
            type = DesktopMessage.PongType,
            requestId,
            protocolVersion = DesktopMessage.ProtocolVersion,
            desktop = true,
            platform = "windows",
            appVersion = version,
        });
        return true;
    }
}
