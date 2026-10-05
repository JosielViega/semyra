using System.Text.Json;
using System.Text.RegularExpressions;

namespace Semyra.Desktop.Desktop;

public static partial class DesktopMessage
{
    public const string PingType = "semyra.desktop.ping";
    public const string PongType = "semyra.desktop.pong";
    public const string HostStatusType = "semyra.desktop.host.status";
    public const string HostStatusResultType = "semyra.desktop.host.status-result";
    public const int ProtocolVersion = 1;

    public static bool TryReadRequest(string json, out string type, out string requestId)
    {
        type = string.Empty;
        requestId = string.Empty;

        try
        {
            using var document = JsonDocument.Parse(json);
            if (document.RootElement.ValueKind != JsonValueKind.Object)
            {
                return false;
            }

            string? candidateType = null;
            string? candidateRequestId = null;
            var hasType = false;
            var hasRequestId = false;
            foreach (var property in document.RootElement.EnumerateObject())
            {
                switch (property.Name)
                {
                    case "type" when !hasType && property.Value.ValueKind == JsonValueKind.String:
                        candidateType = property.Value.GetString();
                        hasType = true;
                        break;
                    case "requestId" when !hasRequestId && property.Value.ValueKind == JsonValueKind.String:
                        candidateRequestId = property.Value.GetString();
                        hasRequestId = true;
                        break;
                    default:
                        return false;
                }
            }

            if ((candidateType != PingType && candidateType != HostStatusType)
                || candidateRequestId is null
                || !RequestIdPattern().IsMatch(candidateRequestId))
            {
                return false;
            }

            type = candidateType;
            requestId = candidateRequestId;
            return true;
        }
        catch (JsonException)
        {
            return false;
        }
    }

    [GeneratedRegex("^[A-Za-z0-9._:-]{1,128}$", RegexOptions.CultureInvariant)]
    private static partial Regex RequestIdPattern();
}
