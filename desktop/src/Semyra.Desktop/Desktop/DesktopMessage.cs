using System.Text.Json;
using System.Text.RegularExpressions;
using System.Globalization;
using Semyra.Desktop.Host;

namespace Semyra.Desktop.Desktop;

public static partial class DesktopMessage
{
    public const string PingType = "semyra.desktop.ping";
    public const string PongType = "semyra.desktop.pong";
    public const string HostStatusType = "semyra.desktop.host.status";
    public const string HostStatusResultType = "semyra.desktop.host.status-result";
    public const string HostAuthorizeType = "semyra.desktop.host.authorize";
    public const string HostAuthorizeResultType = "semyra.desktop.host.authorize-result";
    public const string HostClearType = "semyra.desktop.host.clear";
    public const string HostClearResultType = "semyra.desktop.host.clear-result";
    public const int ProtocolVersion = 1;

    public static bool TryReadRequest(string json, out DesktopRequest request)
    {
        request = new DesktopRequest(string.Empty, string.Empty);

        try
        {
            using var document = JsonDocument.Parse(json);
            if (document.RootElement.ValueKind != JsonValueKind.Object)
            {
                return false;
            }

            var properties = new Dictionary<string, JsonElement>(StringComparer.Ordinal);
            foreach (var property in document.RootElement.EnumerateObject())
            {
                if (!properties.TryAdd(property.Name, property.Value.Clone()))
                {
                    return false;
                }
            }

            if (!TryString(properties, "type", out var type)
                || !TryString(properties, "requestId", out var requestId)
                || !RequestIdPattern().IsMatch(requestId))
            {
                return false;
            }

            if (type is PingType or HostStatusType or HostClearType)
            {
                if (properties.Count != 2)
                {
                    return false;
                }

                request = new DesktopRequest(type, requestId);
                return true;
            }

            if (type != HostAuthorizeType
                || properties.Count != 8
                || !TryString(properties, "hostSessionToken", out var token)
                || !HostSessionTokenPattern().IsMatch(token)
                || !TryString(properties, "roomCode", out var roomCode)
                || !RoomCodePattern().IsMatch(roomCode)
                || !TryString(properties, "transmissionInstanceId", out var instanceId)
                || !InstanceIdPattern().IsMatch(instanceId)
                || !properties.TryGetValue("transmissionRevision", out var revisionValue)
                || revisionValue.ValueKind != JsonValueKind.Number
                || !revisionValue.TryGetInt64(out var revision)
                || revision <= 0
                || !TryString(properties, "permission", out var permission)
                || permission != "media.publish"
                || !TryString(properties, "expiresAt", out var expiresAtValue)
                || !DateTimeOffset.TryParse(
                    expiresAtValue,
                    CultureInfo.InvariantCulture,
                    DateTimeStyles.AssumeUniversal | DateTimeStyles.AdjustToUniversal,
                    out var expiresAt)
                || expiresAt <= DateTimeOffset.UtcNow)
            {
                return false;
            }

            request = new DesktopRequest(type, requestId, new HostAuthorization(
                token,
                roomCode,
                instanceId,
                revision,
                permission,
                expiresAt));
            return true;
        }
        catch (JsonException)
        {
            return false;
        }
    }

    private static bool TryString(
        IReadOnlyDictionary<string, JsonElement> properties,
        string name,
        out string value)
    {
        value = string.Empty;
        if (!properties.TryGetValue(name, out var element) || element.ValueKind != JsonValueKind.String)
        {
            return false;
        }

        value = element.GetString() ?? string.Empty;
        return value.Length > 0;
    }

    [GeneratedRegex("^[A-Za-z0-9._:-]{1,128}$", RegexOptions.CultureInvariant)]
    private static partial Regex RequestIdPattern();

    [GeneratedRegex("^[a-f0-9]{32}\\.[a-f0-9]{64}$", RegexOptions.CultureInvariant)]
    private static partial Regex HostSessionTokenPattern();

    [GeneratedRegex("^[A-Z0-9]{8}$", RegexOptions.CultureInvariant)]
    private static partial Regex RoomCodePattern();

    [GeneratedRegex("^[a-f0-9]{32}$", RegexOptions.CultureInvariant)]
    private static partial Regex InstanceIdPattern();
}
