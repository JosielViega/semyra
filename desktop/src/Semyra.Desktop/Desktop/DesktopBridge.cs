using System.Reflection;
using System.Text.Json;
using Semyra.Desktop.Host;

namespace Semyra.Desktop.Desktop;

public static class DesktopBridge
{
    public static bool TryHandle(string json, HostEngine hostEngine, out string response)
    {
        response = string.Empty;
        if (!DesktopMessage.TryReadRequest(json, out var request))
        {
            return false;
        }

        response = request.Type switch
        {
            DesktopMessage.PingType => CreatePong(request.RequestId),
            DesktopMessage.HostStatusType => CreateHostStatus(request.RequestId, hostEngine.Snapshot()),
            DesktopMessage.HostAuthorizeType => CreateHostAuthorizeResult(
                request.RequestId,
                request.Authorization!,
                hostEngine.Authorize(request.Authorization!)),
            DesktopMessage.HostClearType => CreateHostClearResult(request.RequestId, hostEngine),
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
                authorization = new
                {
                    authorized = snapshot.Authorization.Authorized,
                    permission = snapshot.Authorization.Permission,
                    transmissionInstanceId = snapshot.Authorization.TransmissionInstanceId,
                    transmissionRevision = snapshot.Authorization.TransmissionRevision,
                },
            },
        });
    }

    private static string CreateHostAuthorizeResult(
        string requestId,
        HostAuthorization authorization,
        bool authorized)
    {
        return JsonSerializer.Serialize(new
        {
            type = DesktopMessage.HostAuthorizeResultType,
            requestId,
            protocolVersion = DesktopMessage.ProtocolVersion,
            authorized,
            permission = authorization.Permission,
            transmissionInstanceId = authorization.TransmissionInstanceId,
            transmissionRevision = authorization.TransmissionRevision,
        });
    }

    private static string CreateHostClearResult(string requestId, HostEngine hostEngine)
    {
        hostEngine.ClearAuthorization();
        return JsonSerializer.Serialize(new
        {
            type = DesktopMessage.HostClearResultType,
            requestId,
            protocolVersion = DesktopMessage.ProtocolVersion,
            cleared = true,
        });
    }
}
