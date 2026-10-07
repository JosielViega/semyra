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
    public const string IptvSourcesListType = "semyra.desktop.iptv.sources.list";
    public const string IptvSourcesListResultType = "semyra.desktop.iptv.sources.list-result";
    public const string IptvSourcesAddType = "semyra.desktop.iptv.sources.add";
    public const string IptvSourcesAddResultType = "semyra.desktop.iptv.sources.add-result";
    public const string IptvSourcesRemoveType = "semyra.desktop.iptv.sources.remove";
    public const string IptvSourcesRemoveResultType = "semyra.desktop.iptv.sources.remove-result";
    public const string IptvSourcesRefreshType = "semyra.desktop.iptv.sources.refresh";
    public const string IptvSourcesRefreshResultType = "semyra.desktop.iptv.sources.refresh-result";
    public const string IptvSourcesPickFileType = "semyra.desktop.iptv.sources.pick-file";
    public const string IptvSourcesPickFileResultType = "semyra.desktop.iptv.sources.pick-file-result";
    public const string IptvCatalogGroupsType = "semyra.desktop.iptv.groups.list";
    public const string IptvCatalogGroupsResultType = "semyra.desktop.iptv.groups.list-result";
    public const string IptvCatalogSearchType = "semyra.desktop.iptv.channels.search";
    public const string IptvCatalogSearchResultType = "semyra.desktop.iptv.channels.search-result";
    public const string IptvMediaStartType = "semyra.desktop.iptv.media.start";
    public const string IptvMediaStartResultType = "semyra.desktop.iptv.media.start-result";
    public const string IptvMediaStopType = "semyra.desktop.iptv.media.stop";
    public const string IptvMediaStopResultType = "semyra.desktop.iptv.media.stop-result";
    public const string IptvMediaStatusType = "semyra.desktop.iptv.media.status";
    public const string IptvMediaStatusResultType = "semyra.desktop.iptv.media.status-result";
    public const string IptvMediaStateType = "semyra.desktop.iptv.media.state";
    public const string IptvPublishStartType = "semyra.desktop.iptv.publish.start";
    public const string IptvPublishStartResultType = "semyra.desktop.iptv.publish.start-result";
    public const string IptvPublishStopType = "semyra.desktop.iptv.publish.stop";
    public const string IptvPublishStopResultType = "semyra.desktop.iptv.publish.stop-result";
    public const string IptvPublishStatusType = "semyra.desktop.iptv.publish.status";
    public const string IptvPublishStatusResultType = "semyra.desktop.iptv.publish.status-result";
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

            if (type is IptvSourcesListType or IptvSourcesPickFileType)
            {
                if (properties.Count != 2)
                {
                    return false;
                }
                request = new DesktopRequest(type, requestId, Iptv: new IptvRequest());
                return true;
            }

            if (type is IptvMediaStopType or IptvMediaStatusType or IptvPublishStopType or IptvPublishStatusType)
            {
                if (properties.Count != 2)
                {
                    return false;
                }
                request = new DesktopRequest(type, requestId, Media: new MediaRequest());
                return true;
            }

            if (type is IptvMediaStartType or IptvPublishStartType)
            {
                if (properties.Count != 3 || !TryPositiveInt64(properties, "channelId", out var channelId))
                {
                    return false;
                }
                request = new DesktopRequest(type, requestId, Media: new MediaRequest(channelId));
                return true;
            }

            if (type == IptvSourcesAddType)
            {
                if (properties.Count != 5
                    || !TryString(properties, "sourceType", out var sourceType)
                    || sourceType != "m3u_url"
                    || !TryString(properties, "name", out var name)
                    || name.Length > 100
                    || !TryString(properties, "location", out var location)
                    || location.Length > 4096)
                {
                    return false;
                }
                request = new DesktopRequest(type, requestId, Iptv: new IptvRequest(Name: name, Location: location));
                return true;
            }

            if (type is IptvSourcesRemoveType or IptvSourcesRefreshType or IptvCatalogGroupsType)
            {
                if (properties.Count != 3 || !TryPositiveInt64(properties, "sourceId", out var sourceId))
                {
                    return false;
                }
                request = new DesktopRequest(type, requestId, Iptv: new IptvRequest(SourceId: sourceId));
                return true;
            }

            if (type == IptvCatalogSearchType)
            {
                if (properties.Count != 7
                    || !TryPositiveInt64(properties, "sourceId", out var sourceId)
                    || !TryNullableString(properties, "query", 120, out var query)
                    || !TryNullableString(properties, "group", 240, out var group)
                    || !TryInt32(properties, "offset", out var offset) || offset < 0
                    || !TryInt32(properties, "limit", out var limit) || limit is < 1 or > 100)
                {
                    return false;
                }
                request = new DesktopRequest(type, requestId, Iptv: new IptvRequest(sourceId, Query: query, Group: group, Offset: offset, Limit: limit));
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

    private static bool TryPositiveInt64(IReadOnlyDictionary<string, JsonElement> properties, string name, out long value)
    {
        value = 0;
        return properties.TryGetValue(name, out var element)
            && element.ValueKind == JsonValueKind.Number
            && element.TryGetInt64(out value)
            && value > 0;
    }

    private static bool TryInt32(IReadOnlyDictionary<string, JsonElement> properties, string name, out int value)
    {
        value = 0;
        return properties.TryGetValue(name, out var element)
            && element.ValueKind == JsonValueKind.Number
            && element.TryGetInt32(out value);
    }

    private static bool TryNullableString(IReadOnlyDictionary<string, JsonElement> properties, string name, int maxLength, out string? value)
    {
        value = null;
        if (!properties.TryGetValue(name, out var element))
        {
            return false;
        }
        if (element.ValueKind == JsonValueKind.Null)
        {
            return true;
        }
        if (element.ValueKind != JsonValueKind.String)
        {
            return false;
        }
        value = element.GetString() ?? string.Empty;
        return value.Length <= maxLength;
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
