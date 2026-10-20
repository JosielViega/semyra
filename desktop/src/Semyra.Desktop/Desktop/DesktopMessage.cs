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
    public const string AccountActivateType = "semyra.desktop.account.activate";
    public const string AccountActivateResultType = "semyra.desktop.account.activate-result";
    public const string AccountClearType = "semyra.desktop.account.clear";
    public const string AccountClearResultType = "semyra.desktop.account.clear-result";
    public const string IptvSourcesListType = "semyra.desktop.iptv.sources.list";
    public const string IptvSourcesListResultType = "semyra.desktop.iptv.sources.list-result";
    public const string IptvSourcesAddType = "semyra.desktop.iptv.sources.add";
    public const string IptvSourcesAddResultType = "semyra.desktop.iptv.sources.add-result";
    public const string IptvSourcesAddXtreamType = "semyra.desktop.iptv.sources.add-xtream";
    public const string IptvSourcesAddXtreamResultType = "semyra.desktop.iptv.sources.add-xtream-result";
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
    public const string IptvVodRefreshType = "semyra.desktop.iptv.vod.refresh";
    public const string IptvVodRefreshResultType = "semyra.desktop.iptv.vod.refresh-result";
    public const string IptvVodCategoriesType = "semyra.desktop.iptv.vod.categories";
    public const string IptvVodCategoriesResultType = "semyra.desktop.iptv.vod.categories-result";
    public const string IptvVodMoviesSearchType = "semyra.desktop.iptv.vod.movies.search";
    public const string IptvVodMoviesSearchResultType = "semyra.desktop.iptv.vod.movies.search-result";
    public const string IptvVodSeriesSearchType = "semyra.desktop.iptv.vod.series.search";
    public const string IptvVodSeriesSearchResultType = "semyra.desktop.iptv.vod.series.search-result";
    public const string IptvVodSeriesDetailsType = "semyra.desktop.iptv.vod.series.details";
    public const string IptvVodSeriesDetailsResultType = "semyra.desktop.iptv.vod.series.details-result";
    public const string IptvVodViewStartType = "semyra.desktop.iptv.vod.view-start";
    public const string IptvVodViewStartResultType = "semyra.desktop.iptv.vod.view-start-result";
    public const string IptvFavoritesListType = "semyra.desktop.iptv.favorites.list";
    public const string IptvFavoritesListResultType = "semyra.desktop.iptv.favorites.list-result";
    public const string IptvFavoriteSetType = "semyra.desktop.iptv.favorite.set";
    public const string IptvFavoriteSetResultType = "semyra.desktop.iptv.favorite.set-result";
    public const string IptvRecentsListType = "semyra.desktop.iptv.recents.list";
    public const string IptvRecentsListResultType = "semyra.desktop.iptv.recents.list-result";
    public const string IptvRecentRemoveType = "semyra.desktop.iptv.recent.remove";
    public const string IptvRecentRemoveResultType = "semyra.desktop.iptv.recent.remove-result";
    public const string IptvRecentsClearType = "semyra.desktop.iptv.recents.clear";
    public const string IptvRecentsClearResultType = "semyra.desktop.iptv.recents.clear-result";
    public const string IptvMediaStateType = "semyra.desktop.iptv.media.state";
    public const string IptvViewStartType = "semyra.desktop.iptv.view.start";
    public const string IptvViewStartResultType = "semyra.desktop.iptv.view.start-result";
    public const string IptvViewStopType = "semyra.desktop.iptv.view.stop";
    public const string IptvViewStopResultType = "semyra.desktop.iptv.view.stop-result";
    public const string IptvViewStatusType = "semyra.desktop.iptv.view.status";
    public const string IptvViewStatusResultType = "semyra.desktop.iptv.view.status-result";
    public const string IptvPlayerControlType = "semyra.desktop.iptv.player.control";
    public const string IptvPlayerControlResultType = "semyra.desktop.iptv.player.control-result";
    public const string IptvPlayerGeometryType = "semyra.desktop.player.geometry";
    public const string IptvPlayerGeometryResultType = "semyra.desktop.player.geometry-result";
    public const string IptvPlayerFullscreenType = "semyra.desktop.player.fullscreen";
    public const string IptvPlayerFullscreenResultType = "semyra.desktop.player.fullscreen-result";
    public const string PlayerControlsVisibilityType = "semyra.desktop.player.controls-visibility";
    public const string IptvPublishStartType = "semyra.desktop.iptv.publish.start";
    public const string IptvPublishStartResultType = "semyra.desktop.iptv.publish.start-result";
    public const string IptvPublishStopType = "semyra.desktop.iptv.publish.stop";
    public const string IptvPublishStopResultType = "semyra.desktop.iptv.publish.stop-result";
    public const string IptvPublishStatusType = "semyra.desktop.iptv.publish.status";
    public const string IptvPublishStatusResultType = "semyra.desktop.iptv.publish.status-result";
    public const int ProtocolVersion = 1;

    public static bool TryReadPlayerControlsVisibility(string json, out bool hidden)
    {
        hidden = false;
        try
        {
            using var document = JsonDocument.Parse(json);
            if (document.RootElement.ValueKind != JsonValueKind.Object) return false;
            string? type = null;
            bool? parsedHidden = null;
            var count = 0;
            foreach (var property in document.RootElement.EnumerateObject())
            {
                count++;
                if (property.NameEquals("type") && property.Value.ValueKind == JsonValueKind.String)
                {
                    type = property.Value.GetString();
                }
                else if (property.NameEquals("hidden")
                    && property.Value.ValueKind is JsonValueKind.True or JsonValueKind.False)
                {
                    parsedHidden = property.Value.GetBoolean();
                }
                else
                {
                    return false;
                }
            }
            if (count != 2 || type != PlayerControlsVisibilityType || parsedHidden is null) return false;
            hidden = parsedHidden.Value;
            return true;
        }
        catch (JsonException)
        {
            return false;
        }
    }

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

            if (type is PingType or HostStatusType or HostClearType or AccountClearType)
            {
                if (properties.Count != 2)
                {
                    return false;
                }

                request = new DesktopRequest(type, requestId);
                return true;
            }

            if (type == AccountActivateType)
            {
                if (properties.Count != 3 || !TryString(properties, "profileId", out var profileId) || !ProfileIdPattern().IsMatch(profileId))
                {
                    return false;
                }
                request = new DesktopRequest(type, requestId, Account: new AccountRequest(profileId));
                return true;
            }

            if (type is IptvSourcesListType or IptvSourcesPickFileType)
            {
                if (properties.Count != 3 || !TryAccountContext(properties, out var contextId))
                {
                    return false;
                }
                request = new DesktopRequest(type, requestId, Iptv: new IptvRequest(AccountContextId: contextId));
                return true;
            }

            if (type is IptvPublishStopType or IptvPublishStatusType
                or IptvViewStopType or IptvViewStatusType)
            {
                if (properties.Count != 3 || !TryAccountContext(properties, out var contextId))
                {
                    return false;
                }
                request = new DesktopRequest(type, requestId, Media: new MediaRequest(AccountContextId: contextId));
                return true;
            }

            if (type is IptvPublishStartType or IptvViewStartType)
            {
                if (properties.Count != 4 || !TryPositiveInt64(properties, "channelId", out var channelId) || !TryAccountContext(properties, out var contextId))
                {
                    return false;
                }
                request = new DesktopRequest(type, requestId, Media: new MediaRequest(channelId, contextId));
                return true;
            }

            if (type == IptvVodViewStartType)
            {
                if (properties.Count != 5
                    || !TryAccountContext(properties, out var contextId)
                    || !TryString(properties, "kind", out var kind) || kind is not ("movie" or "episode")
                    || !TryPositiveInt64(properties, "mediaId", out var mediaId))
                {
                    return false;
                }
                request = new DesktopRequest(type, requestId, Media: new MediaRequest(AccountContextId: contextId, Kind: kind, MediaId: mediaId));
                return true;
            }

            if (type == IptvPlayerControlType)
            {
                if (properties.Count is < 5 or > 6
                    || !TryAccountContext(properties, out var contextId)
                    || !TryString(properties, "sessionId", out var sessionId) || !SessionIdPattern().IsMatch(sessionId)
                    || !TryString(properties, "command", out var command)
                    || command is not ("pause" or "play" or "seek-relative" or "seek-absolute" or "mute" or "unmute" or "volume" or "stop"))
                {
                    return false;
                }
                double? value = null;
                if (command is "seek-relative" or "seek-absolute" or "volume")
                {
                    if (properties.Count != 6 || !TryDouble(properties, "value", out var parsed)) return false;
                    value = parsed;
                }
                else if (properties.Count != 5)
                {
                    return false;
                }
                request = new DesktopRequest(type, requestId, Player: new PlayerRequest(contextId, sessionId, command, value));
                return true;
            }

            if (type == IptvPlayerGeometryType)
            {
                if (properties.Count != 11
                    || !TryAccountContext(properties, out var contextId)
                    || !TryString(properties, "sessionId", out var sessionId) || !SessionIdPattern().IsMatch(sessionId)
                    || !TryDouble(properties, "x", out var x)
                    || !TryDouble(properties, "y", out var y)
                    || !TryDouble(properties, "width", out var width)
                    || !TryDouble(properties, "height", out var height)
                    || !TryDouble(properties, "viewportWidth", out var viewportWidth)
                    || !TryDouble(properties, "viewportHeight", out var viewportHeight)
                    || !TryBoolean(properties, "visible", out var visible))
                {
                    return false;
                }
                request = new DesktopRequest(type, requestId, Player: new PlayerRequest(
                    contextId, sessionId, X: x, Y: y, Width: width, Height: height,
                    ViewportWidth: viewportWidth, ViewportHeight: viewportHeight, Visible: visible));
                return true;
            }

            if (type == IptvPlayerFullscreenType)
            {
                if (properties.Count != 5
                    || !TryAccountContext(properties, out var contextId)
                    || !TryString(properties, "sessionId", out var sessionId) || !SessionIdPattern().IsMatch(sessionId)
                    || !TryBoolean(properties, "fullscreen", out var fullscreen))
                {
                    return false;
                }
                request = new DesktopRequest(type, requestId, Player: new PlayerRequest(
                    contextId, sessionId, Fullscreen: fullscreen));
                return true;
            }

            if (type == IptvSourcesAddType)
            {
                if (properties.Count != 6
                    || !TryAccountContext(properties, out var contextId)
                    || !TryString(properties, "sourceType", out var sourceType)
                    || sourceType != "m3u_url"
                    || !TryString(properties, "name", out var name)
                    || name.Length > 100
                    || !TryString(properties, "location", out var location)
                    || location.Length > 4096)
                {
                    return false;
                }
                request = new DesktopRequest(type, requestId, Iptv: new IptvRequest(Name: name, Location: location, AccountContextId: contextId));
                return true;
            }

            if (type == IptvSourcesAddXtreamType)
            {
                if (properties.Count != 7
                    || !TryAccountContext(properties, out var contextId)
                    || !TryString(properties, "name", out var name) || name.Length > 100
                    || !TryString(properties, "baseUrl", out var baseUrl) || baseUrl.Length > 2048
                    || !TryString(properties, "username", out var username) || username.Length > 512
                    || !TryString(properties, "password", out var password) || password.Length > 512)
                {
                    return false;
                }
                request = new DesktopRequest(type, requestId, Iptv: new IptvRequest(
                    Name: name,
                    AccountContextId: contextId,
                    BaseUrl: baseUrl,
                    Username: username,
                    Password: password));
                return true;
            }

            if (type is IptvSourcesRemoveType or IptvSourcesRefreshType or IptvCatalogGroupsType or IptvVodRefreshType)
            {
                if (properties.Count != 4 || !TryPositiveInt64(properties, "sourceId", out var sourceId) || !TryAccountContext(properties, out var contextId))
                {
                    return false;
                }
                request = new DesktopRequest(type, requestId, Iptv: new IptvRequest(SourceId: sourceId, AccountContextId: contextId));
                return true;
            }

            if (type == IptvVodCategoriesType)
            {
                if (properties.Count != 5 || !TryPositiveInt64(properties, "sourceId", out var sourceId) || !TryAccountContext(properties, out var contextId) || !TryString(properties, "mediaType", out var mediaType) || mediaType is not ("movie" or "series")) return false;
                request = new DesktopRequest(type, requestId, Iptv: new IptvRequest(SourceId: sourceId, AccountContextId: contextId, MediaType: mediaType)); return true;
            }

            if (type is IptvVodMoviesSearchType or IptvVodSeriesSearchType)
            {
                if (properties.Count != 8 || !TryPositiveInt64(properties, "sourceId", out var sourceId) || !TryAccountContext(properties, out var contextId) || !TryNullableString(properties, "query", 120, out var query) || !TryNullableString(properties, "category", 128, out var category) || !TryInt32(properties, "offset", out var offset) || offset < 0 || !TryInt32(properties, "limit", out var limit) || limit is < 1 or > 100) return false;
                request = new DesktopRequest(type, requestId, Iptv: new IptvRequest(SourceId: sourceId, AccountContextId: contextId, Query: query, Category: category, Offset: offset, Limit: limit)); return true;
            }

            if (type == IptvVodSeriesDetailsType)
            {
                if (properties.Count != 5 || !TryPositiveInt64(properties, "seriesId", out var seriesId) || !TryAccountContext(properties, out var contextId) || !TryBoolean(properties, "refresh", out var refresh)) return false;
                request = new DesktopRequest(type, requestId, Iptv: new IptvRequest(SeriesId: seriesId, AccountContextId: contextId, Refresh: refresh)); return true;
            }

            if (type == IptvRecentsListType)
            {
                if (properties.Count != 4 || !TryPositiveInt64(properties, "sourceId", out var sourceId) || !TryAccountContext(properties, out var contextId))
                {
                    return false;
                }
                request = new DesktopRequest(type, requestId, Iptv: new IptvRequest(SourceId: sourceId, AccountContextId: contextId));
                return true;
            }

            if (type == IptvRecentRemoveType)
            {
                if (properties.Count != 5
                    || !TryPositiveInt64(properties, "sourceId", out var sourceId)
                    || !TryPositiveInt64(properties, "recentId", out var recentId)
                    || !TryAccountContext(properties, out var contextId))
                {
                    return false;
                }
                request = new DesktopRequest(type, requestId, Iptv: new IptvRequest(
                    SourceId: sourceId, RecentId: recentId, AccountContextId: contextId));
                return true;
            }

            if (type == IptvRecentsClearType)
            {
                if (properties.Count != 4 || !TryPositiveInt64(properties, "sourceId", out var sourceId) || !TryAccountContext(properties, out var contextId))
                {
                    return false;
                }
                request = new DesktopRequest(type, requestId, Iptv: new IptvRequest(SourceId: sourceId, AccountContextId: contextId));
                return true;
            }

            if (type == IptvFavoriteSetType)
            {
                if (properties.Count != 5
                    || !TryPositiveInt64(properties, "channelId", out var channelId)
                    || !TryBoolean(properties, "favorite", out var favorite)
                    || !TryAccountContext(properties, out var contextId))
                {
                    return false;
                }
                request = new DesktopRequest(type, requestId, Iptv: new IptvRequest(ChannelId: channelId, Favorite: favorite, AccountContextId: contextId));
                return true;
            }

            if (type == IptvFavoritesListType)
            {
                if (properties.Count != 6
                    || !TryPositiveInt64(properties, "sourceId", out var sourceId)
                    || !TryInt32(properties, "offset", out var offset) || offset < 0
                    || !TryInt32(properties, "limit", out var limit) || limit is < 1 or > 50
                    || !TryAccountContext(properties, out var contextId))
                {
                    return false;
                }
                request = new DesktopRequest(type, requestId, Iptv: new IptvRequest(SourceId: sourceId, Offset: offset, Limit: limit, AccountContextId: contextId));
                return true;
            }

            if (type == IptvCatalogSearchType)
            {
                if (properties.Count != 8
                    || !TryAccountContext(properties, out var contextId)
                    || !TryPositiveInt64(properties, "sourceId", out var sourceId)
                    || !TryNullableString(properties, "query", 120, out var query)
                    || !TryNullableString(properties, "group", 240, out var group)
                    || !TryInt32(properties, "offset", out var offset) || offset < 0
                    || !TryInt32(properties, "limit", out var limit) || limit is < 1 or > 100)
                {
                    return false;
                }
                request = new DesktopRequest(type, requestId, Iptv: new IptvRequest(sourceId, Query: query, Group: group, Offset: offset, Limit: limit, AccountContextId: contextId));
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

    private static bool TryAccountContext(IReadOnlyDictionary<string, JsonElement> properties, out string contextId) =>
        TryString(properties, "accountContextId", out contextId) && AccountContextIdPattern().IsMatch(contextId);

    private static bool TryInt32(IReadOnlyDictionary<string, JsonElement> properties, string name, out int value)
    {
        value = 0;
        return properties.TryGetValue(name, out var element)
            && element.ValueKind == JsonValueKind.Number
            && element.TryGetInt32(out value);
    }

    private static bool TryDouble(IReadOnlyDictionary<string, JsonElement> properties, string name, out double value)
    {
        value = 0;
        return properties.TryGetValue(name, out var element)
            && element.ValueKind == JsonValueKind.Number
            && element.TryGetDouble(out value)
            && double.IsFinite(value);
    }

    private static bool TryBoolean(IReadOnlyDictionary<string, JsonElement> properties, string name, out bool value)
    {
        value = false;
        if (!properties.TryGetValue(name, out var element) || element.ValueKind is not (JsonValueKind.True or JsonValueKind.False))
        {
            return false;
        }
        value = element.GetBoolean();
        return true;
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

    [GeneratedRegex("^[a-f0-9]{64}$", RegexOptions.CultureInvariant)]
    private static partial Regex ProfileIdPattern();

    [GeneratedRegex("^[a-f0-9]{32}$", RegexOptions.CultureInvariant)]
    private static partial Regex AccountContextIdPattern();

    [GeneratedRegex("^[a-f0-9]{32}$", RegexOptions.CultureInvariant)]
    private static partial Regex SessionIdPattern();
}
