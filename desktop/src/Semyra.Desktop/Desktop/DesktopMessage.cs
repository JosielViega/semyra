using System.Text.Json;
using System.Text.RegularExpressions;

namespace Semyra.Desktop.Desktop;

public static partial class DesktopMessage
{
    public const string PingType = "semyra.desktop.ping";
    public const string PongType = "semyra.desktop.pong";
    public const int ProtocolVersion = 1;

    public static bool TryReadPing(string json, out string requestId)
    {
        requestId = string.Empty;

        try
        {
            using var document = JsonDocument.Parse(json);
            if (document.RootElement.ValueKind != JsonValueKind.Object)
            {
                return false;
            }

            string? type = null;
            string? candidateRequestId = null;
            foreach (var property in document.RootElement.EnumerateObject())
            {
                switch (property.Name)
                {
                    case "type" when property.Value.ValueKind == JsonValueKind.String:
                        type = property.Value.GetString();
                        break;
                    case "requestId" when property.Value.ValueKind == JsonValueKind.String:
                        candidateRequestId = property.Value.GetString();
                        break;
                    default:
                        return false;
                }
            }

            if (type != PingType
                || candidateRequestId is null
                || !RequestIdPattern().IsMatch(candidateRequestId))
            {
                return false;
            }

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
