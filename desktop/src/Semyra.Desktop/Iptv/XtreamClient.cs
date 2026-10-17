using System.IO;
using System.Net;
using System.Net.Http;
using System.Net.Http.Headers;
using System.Text.Json;

namespace Semyra.Desktop.Iptv;

internal sealed class XtreamClient
{
    internal const string UserAgent = "SemyraDesktop/1.0 IPTV";
    internal const int MaximumResponseBytes = 128 * 1024 * 1024;
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
        using var document = await GetJsonAsync(configuration, null, cancellationToken).ConfigureAwait(false);
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
        using var document = await GetJsonAsync(configuration, "get_live_categories", cancellationToken).ConfigureAwait(false);
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
        using var document = await GetJsonAsync(configuration, "get_live_streams", cancellationToken).ConfigureAwait(false);
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

    internal static string BuildStreamUrl(XtreamSourceConfiguration configuration, string streamId)
    {
        return $"{configuration.BaseUrl}/live/{Uri.EscapeDataString(configuration.Username)}/{Uri.EscapeDataString(configuration.Password)}/{Uri.EscapeDataString(streamId)}.ts";
    }

    private async Task<JsonDocument> GetJsonAsync(
        XtreamSourceConfiguration configuration,
        string? action,
        CancellationToken cancellationToken)
    {
        using var timeout = CancellationTokenSource.CreateLinkedTokenSource(cancellationToken);
        timeout.CancelAfter(_requestTimeout);
        try
        {
            using var request = new HttpRequestMessage(HttpMethod.Get, BuildApiUri(configuration, action));
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

    private static Uri BuildApiUri(XtreamSourceConfiguration configuration, string? action)
    {
        var query = $"username={Uri.EscapeDataString(configuration.Username)}&password={Uri.EscapeDataString(configuration.Password)}";
        if (action is not null) query += $"&action={Uri.EscapeDataString(action)}";
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
