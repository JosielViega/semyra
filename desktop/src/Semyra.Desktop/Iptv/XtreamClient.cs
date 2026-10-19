using System.IO;
using System.Net;
using System.Net.Http;
using System.Net.Http.Headers;
using System.Text.Json;

namespace Semyra.Desktop.Iptv;

internal sealed class XtreamClient
{
    internal const string UserAgent = "SemyraDesktop/1.0 IPTV";
    internal const int MaximumResponseBytes = 256 * 1024 * 1024;
    internal static readonly TimeSpan RequestTimeout = TimeSpan.FromSeconds(15);

    private readonly HttpClient _httpClient;
    private readonly TimeSpan _requestTimeout;

    public XtreamClient(HttpClient httpClient, TimeSpan? requestTimeout = null)
    {
        _httpClient = httpClient;
        _requestTimeout = requestTimeout ?? RequestTimeout;
    }

    public async Task AuthenticateAsync(XtreamSourceConfiguration configuration, CancellationToken cancellationToken)
    {
        using var document = await GetJsonAsync(configuration, null, null, cancellationToken).ConfigureAwait(false);
        if (document.RootElement.ValueKind != JsonValueKind.Object
            || !document.RootElement.TryGetProperty("user_info", out var userInfo)
            || userInfo.ValueKind != JsonValueKind.Object
            || !userInfo.TryGetProperty("auth", out var auth)
            || !IsAuthenticated(auth))
        {
            throw new XtreamException("xtream_auth_failed");
        }
    }

    public async Task<IReadOnlyList<XtreamCategory>> GetLiveCategoriesAsync(
        XtreamSourceConfiguration configuration,
        CancellationToken cancellationToken)
    {
        using var document = await GetJsonAsync(configuration, "get_live_categories", null, cancellationToken).ConfigureAwait(false);
        if (document.RootElement.ValueKind != JsonValueKind.Array)
        {
            throw new XtreamException("xtream_invalid_response");
        }

        var categories = new Dictionary<string, XtreamCategory>(StringComparer.Ordinal);
        foreach (var item in document.RootElement.EnumerateArray())
        {
            if (item.ValueKind != JsonValueKind.Object
                || !TryScalarString(item, "category_id", out var id)
                || !TryScalarString(item, "category_name", out var name)
                || string.IsNullOrWhiteSpace(id)
                || string.IsNullOrWhiteSpace(name))
            {
                continue;
            }
            categories.TryAdd(id, new XtreamCategory(id, name.Trim()));
        }
        return categories.Values.ToArray();
    }

    public async Task<XtreamImportResult> GetLiveStreamsAsync(
        XtreamSourceConfiguration configuration,
        IReadOnlyDictionary<string, string> categories,
        CancellationToken cancellationToken)
    {
        using var document = await GetJsonAsync(configuration, "get_live_streams", null, cancellationToken).ConfigureAwait(false);
        if (document.RootElement.ValueKind != JsonValueKind.Array)
        {
            throw new XtreamException("xtream_invalid_response");
        }

        var channels = new Dictionary<string, ParsedM3uChannel>(StringComparer.Ordinal);
        var received = 0;
        var skipped = 0;
        foreach (var item in document.RootElement.EnumerateArray())
        {
            received++;
            if (item.ValueKind != JsonValueKind.Object
                || !TryScalarString(item, "stream_id", out var streamId)
                || !TryScalarString(item, "name", out var name)
                || string.IsNullOrWhiteSpace(streamId)
                || string.IsNullOrWhiteSpace(name))
            {
                skipped++;
                continue;
            }
            streamId = streamId.Trim();
            if (channels.ContainsKey(streamId))
            {
                skipped++;
                continue;
            }

            TryScalarString(item, "category_id", out var categoryId);
            TryScalarString(item, "stream_icon", out var logo);
            TryScalarString(item, "epg_channel_id", out var epgId);
            categories.TryGetValue(categoryId ?? string.Empty, out var group);
            channels.Add(streamId, new ParsedM3uChannel(
                name.Trim(),
                BuildStreamUrl(configuration, streamId),
                EmptyToNull(epgId),
                SafeOptionalHttpUrl(logo, configuration),
                group,
                streamId,
                $"xtream:live:{streamId}"));
        }

        return new XtreamImportResult(channels.Values.ToArray(), received, skipped);
    }

    public Task<IReadOnlyList<XtreamVodCategory>> GetVodCategoriesAsync(XtreamSourceConfiguration configuration, CancellationToken cancellationToken) =>
        GetCategoriesAsync(configuration, "get_vod_categories", IptvVodMediaType.Movie, cancellationToken);

    public Task<IReadOnlyList<XtreamVodCategory>> GetSeriesCategoriesAsync(XtreamSourceConfiguration configuration, CancellationToken cancellationToken) =>
        GetCategoriesAsync(configuration, "get_series_categories", IptvVodMediaType.Series, cancellationToken);

    private async Task<IReadOnlyList<XtreamVodCategory>> GetCategoriesAsync(XtreamSourceConfiguration configuration, string action, IptvVodMediaType type, CancellationToken cancellationToken)
    {
        using var document = await GetJsonAsync(configuration, action, null, cancellationToken).ConfigureAwait(false);
        if (document.RootElement.ValueKind != JsonValueKind.Array) throw new XtreamException("xtream_invalid_response");
        var result = new Dictionary<string, XtreamVodCategory>(StringComparer.Ordinal); var order = 0;
        foreach (var item in document.RootElement.EnumerateArray())
        {
            if (!TryScalarString(item, "category_id", out var id) || !TryScalarString(item, "category_name", out var name) || string.IsNullOrWhiteSpace(id) || string.IsNullOrWhiteSpace(name)) continue;
            result.TryAdd(id.Trim(), new(id.Trim(), name.Trim(), type, order++));
        }
        return result.Values.ToArray();
    }

    public async Task<IReadOnlyList<XtreamMovie>> GetVodStreamsAsync(XtreamSourceConfiguration configuration, CancellationToken cancellationToken)
    {
        using var document = await GetJsonAsync(configuration, "get_vod_streams", null, cancellationToken).ConfigureAwait(false);
        if (document.RootElement.ValueKind != JsonValueKind.Array) throw new XtreamException("xtream_invalid_response");
        var result = new Dictionary<string, XtreamMovie>(StringComparer.Ordinal);
        foreach (var item in document.RootElement.EnumerateArray())
        {
            if (!TryScalarString(item, "stream_id", out var id) || !TryScalarString(item, "name", out var name) || string.IsNullOrWhiteSpace(id) || string.IsNullOrWhiteSpace(name)) continue;
            id = id.Trim(); if (result.ContainsKey(id)) continue;
            result.Add(id, new(id, name.Trim(), Value(item, "category_id"), SafeOptionalHttpUrl(Value(item, "stream_icon"), configuration), SafeExtension(Value(item, "container_extension")), Value(item, "year"), Value(item, "rating"), Value(item, "plot"), Value(item, "genre"), Value(item, "release_date"), Value(item, "added")));
        }
        return result.Values.ToArray();
    }

    public async Task<IReadOnlyList<XtreamSeries>> GetSeriesAsync(XtreamSourceConfiguration configuration, CancellationToken cancellationToken)
    {
        using var document = await GetJsonAsync(configuration, "get_series", null, cancellationToken).ConfigureAwait(false);
        if (document.RootElement.ValueKind != JsonValueKind.Array) throw new XtreamException("xtream_invalid_response");
        var result = new Dictionary<string, XtreamSeries>(StringComparer.Ordinal);
        foreach (var item in document.RootElement.EnumerateArray())
        {
            if (!TryScalarString(item, "series_id", out var id) || !TryScalarString(item, "name", out var name) || string.IsNullOrWhiteSpace(id) || string.IsNullOrWhiteSpace(name)) continue;
            id = id.Trim(); if (result.ContainsKey(id)) continue;
            result.Add(id, new(id, name.Trim(), Value(item, "category_id"), SafeOptionalHttpUrl(Value(item, "cover"), configuration), Value(item, "year"), Value(item, "rating"), Value(item, "plot"), Value(item, "genre"), Value(item, "releaseDate") ?? Value(item, "release_date"), Value(item, "last_modified") ?? Value(item, "added")));
        }
        return result.Values.ToArray();
    }

    public async Task<IReadOnlyList<XtreamEpisode>> GetSeriesInfoAsync(XtreamSourceConfiguration configuration, string seriesId, CancellationToken cancellationToken)
    {
        using var document = await GetJsonAsync(configuration, "get_series_info", new Dictionary<string, string> { { "series_id", seriesId } }, cancellationToken).ConfigureAwait(false);
        if (document.RootElement.ValueKind != JsonValueKind.Object || !document.RootElement.TryGetProperty("episodes", out var groups) || groups.ValueKind != JsonValueKind.Object) throw new XtreamException("xtream_invalid_response");
        var result = new Dictionary<string, XtreamEpisode>(StringComparer.Ordinal);
        foreach (var season in groups.EnumerateObject())
        {
            if (!int.TryParse(season.Name, out var seasonNumber) || seasonNumber < 0 || season.Value.ValueKind != JsonValueKind.Array) continue;
            foreach (var item in season.Value.EnumerateArray())
            {
                if (!TryScalarString(item, "id", out var id) || string.IsNullOrWhiteSpace(id)) continue; id = id.Trim(); if (result.ContainsKey(id)) continue;
                var number = ParseInt(Value(item, "episode_num")); var name = Value(item, "title") ?? $"Episódio {number?.ToString() ?? id}";
                var info = item.TryGetProperty("info", out var nested) && nested.ValueKind == JsonValueKind.Object ? nested : item;
                result.Add(id, new(id, seasonNumber, number, name.Trim(), SafeExtension(Value(item, "container_extension")), Value(info, "duration"), Value(info, "plot"), Value(item, "added")));
            }
        }
        return result.Values.OrderBy(x => x.SeasonNumber).ThenBy(x => x.EpisodeNumber ?? int.MaxValue).ThenBy(x => x.EpisodeId, StringComparer.Ordinal).ToArray();
    }

    internal static string BuildStreamUrl(XtreamSourceConfiguration configuration, string streamId)
    {
        return $"{configuration.BaseUrl}/live/{Uri.EscapeDataString(configuration.Username)}/{Uri.EscapeDataString(configuration.Password)}/{Uri.EscapeDataString(streamId)}.ts";
    }

    internal static string BuildVodUrl(XtreamSourceConfiguration configuration, string kind, string id, string? extension)
    {
        if (kind is not ("movie" or "series")) throw new ArgumentOutOfRangeException(nameof(kind));
        return $"{configuration.BaseUrl}/{kind}/{Uri.EscapeDataString(configuration.Username)}/{Uri.EscapeDataString(configuration.Password)}/{Uri.EscapeDataString(id)}.{SafeExtension(extension) ?? "mp4"}";
    }

    private async Task<JsonDocument> GetJsonAsync(
        XtreamSourceConfiguration configuration,
        string? action,
        IReadOnlyDictionary<string, string>? parameters,
        CancellationToken cancellationToken)
    {
        using var timeout = CancellationTokenSource.CreateLinkedTokenSource(cancellationToken);
        timeout.CancelAfter(_requestTimeout);
        try
        {
            using var request = new HttpRequestMessage(HttpMethod.Get, BuildApiUri(configuration, action, parameters));
            request.Headers.UserAgent.ParseAdd(UserAgent);
            using var response = await _httpClient.SendAsync(
                request,
                HttpCompletionOption.ResponseHeadersRead,
                timeout.Token).ConfigureAwait(false);
            if (response.StatusCode is HttpStatusCode.Unauthorized or HttpStatusCode.Forbidden)
            {
                throw new XtreamException("xtream_auth_failed");
            }
            if (!response.IsSuccessStatusCode)
            {
                throw new XtreamException("xtream_connect_failed");
            }
            if (response.Content.Headers.ContentLength > MaximumResponseBytes)
            {
                throw new XtreamException("xtream_invalid_response");
            }

            await using var input = await response.Content.ReadAsStreamAsync(timeout.Token).ConfigureAwait(false);
            await using var bounded = new MemoryStream();
            var buffer = new byte[64 * 1024];
            while (true)
            {
                var read = await input.ReadAsync(buffer, timeout.Token).ConfigureAwait(false);
                if (read == 0) break;
                if (bounded.Length + read > MaximumResponseBytes)
                {
                    throw new XtreamException("xtream_invalid_response");
                }
                await bounded.WriteAsync(buffer.AsMemory(0, read), timeout.Token).ConfigureAwait(false);
            }
            if (bounded.Length == 0)
            {
                throw new XtreamException("xtream_invalid_response");
            }
            bounded.Position = 0;
            try
            {
                return await JsonDocument.ParseAsync(bounded, cancellationToken: timeout.Token).ConfigureAwait(false);
            }
            catch (JsonException exception)
            {
                throw new XtreamException("xtream_invalid_response", exception);
            }
        }
        catch (OperationCanceledException) when (!cancellationToken.IsCancellationRequested)
        {
            throw new XtreamException("xtream_timeout");
        }
        catch (HttpRequestException)
        {
            throw new XtreamException("xtream_connect_failed");
        }
    }

    private static Uri BuildApiUri(XtreamSourceConfiguration configuration, string? action, IReadOnlyDictionary<string, string>? parameters)
    {
        var query = $"username={Uri.EscapeDataString(configuration.Username)}&password={Uri.EscapeDataString(configuration.Password)}";
        if (action is not null) query += $"&action={Uri.EscapeDataString(action)}";
        if (parameters is not null)
        {
            foreach (var pair in parameters)
            {
                query += $"&{Uri.EscapeDataString(pair.Key)}={Uri.EscapeDataString(pair.Value)}";
            }
        }
        return new Uri($"{configuration.BaseUrl}/player_api.php?{query}", UriKind.Absolute);
    }

    private static bool IsAuthenticated(JsonElement value) =>
        (value.ValueKind == JsonValueKind.Number && value.TryGetInt32(out var number) && number == 1)
        || (value.ValueKind == JsonValueKind.String && value.GetString() == "1");

    private static bool TryScalarString(JsonElement item, string property, out string? value)
    {
        value = null;
        if (!item.TryGetProperty(property, out var element)) return false;
        if (element.ValueKind == JsonValueKind.String)
        {
            value = element.GetString();
            return true;
        }
        if (element.ValueKind == JsonValueKind.Number)
        {
            value = element.GetRawText();
            return true;
        }
        return false;
    }

    private static string? EmptyToNull(string? value) => string.IsNullOrWhiteSpace(value) ? null : value.Trim();

    private static string? Value(JsonElement item, string property) => TryScalarString(item, property, out var value) ? EmptyToNull(value) : null;
    private static int? ParseInt(string? value) => int.TryParse(value, out var result) && result >= 0 ? result : null;
    private static string? SafeExtension(string? value)
    {
        var extension = EmptyToNull(value)?.TrimStart('.').ToLowerInvariant();
        return extension is not null && extension.Length <= 10 && extension.All(char.IsLetterOrDigit) ? extension : null;
    }

    private static string? SafeOptionalHttpUrl(string? value, XtreamSourceConfiguration configuration)
    {
        if (!Uri.TryCreate(value, UriKind.Absolute, out var uri)
            || uri.Scheme is not ("http" or "https")
            || !string.IsNullOrEmpty(uri.UserInfo)
            || !string.IsNullOrEmpty(uri.Query)
            || !string.IsNullOrEmpty(uri.Fragment))
        {
            return null;
        }
        var absolute = uri.AbsoluteUri;
        return absolute.Contains(Uri.EscapeDataString(configuration.Username), StringComparison.OrdinalIgnoreCase)
            || absolute.Contains(Uri.EscapeDataString(configuration.Password), StringComparison.OrdinalIgnoreCase)
            ? null
            : absolute;
    }
}

internal sealed class XtreamException : Exception
{
    public XtreamException(string code, Exception? innerException = null) : base(code, innerException)
    {
        Code = code;
    }

    public string Code { get; }
}
