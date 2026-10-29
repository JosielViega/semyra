using System.Net.Http.Headers;
using System.Net.Http.Json;
using System.Net.Http;
using System.Text.Json;
using Semyra.Desktop.Desktop;
using Semyra.Desktop.Host;

namespace Semyra.Desktop.Media;

internal sealed record DesktopPublishLease(string IngressId, Uri WhipEndpoint);
internal sealed record DesktopPublishStatus(string State);

internal interface IDesktopPublishClient
{
    Task<DesktopPublishLease> StartAsync(HostAuthorization authorization, CancellationToken cancellationToken);
    Task<DesktopPublishStatus> StatusAsync(HostAuthorization authorization, string ingressId, CancellationToken cancellationToken);
    Task StopAsync(HostAuthorization authorization, string ingressId, CancellationToken cancellationToken);
}

internal sealed class DesktopPublishClient(Func<Uri> baseUri, HttpClient? httpClient = null) : IDesktopPublishClient
{
    private readonly HttpClient _http = httpClient ?? CreateHttpClient();

    public async Task<DesktopPublishLease> StartAsync(HostAuthorization authorization, CancellationToken cancellationToken)
    {
        using var request = CreateRequest(authorization, "start", new
        {
            transmission_instance_id = authorization.TransmissionInstanceId,
            transmission_revision = authorization.TransmissionRevision,
        });
        using var response = await _http.SendAsync(request, cancellationToken);
        if (!response.IsSuccessStatusCode)
        {
            var upstreamCode = await ReadErrorCodeAsync(response, cancellationToken);
            throw new MediaEngineException(response.StatusCode == System.Net.HttpStatusCode.Unauthorized
                ? MapUnauthorizedError(upstreamCode) : "ingress_create_failed");
        }
        using var json = await JsonDocument.ParseAsync(await response.Content.ReadAsStreamAsync(cancellationToken), cancellationToken: cancellationToken);
        var root = json.RootElement;
        var ingressId = root.GetProperty("ingress_id").GetString();
        var endpointValue = root.GetProperty("whip_endpoint").GetString();
        var instanceId = root.GetProperty("transmission_instance_id").GetString();
        var revision = root.GetProperty("transmission_revision").GetInt64();
        if (string.IsNullOrWhiteSpace(ingressId)
            || ingressId.Length > 128
            || instanceId != authorization.TransmissionInstanceId
            || revision != authorization.TransmissionRevision
            || !Uri.TryCreate(endpointValue, UriKind.Absolute, out var endpoint)
            || endpoint.Scheme != Uri.UriSchemeHttps)
        {
            throw new MediaEngineException("ingress_create_failed");
        }
        return new DesktopPublishLease(ingressId, endpoint);
    }

    public async Task StopAsync(HostAuthorization authorization, string ingressId, CancellationToken cancellationToken)
    {
        using var request = CreateRequest(authorization, "stop", new
        {
            ingress_id = ingressId,
            transmission_instance_id = authorization.TransmissionInstanceId,
            transmission_revision = authorization.TransmissionRevision,
        });
        try
        {
            using var response = await _http.SendAsync(request, cancellationToken);
            if (response.IsSuccessStatusCode || response.StatusCode == System.Net.HttpStatusCode.NotFound) return;
            var upstreamCode = await ReadErrorCodeAsync(response, cancellationToken);
            throw new MediaEngineException(response.StatusCode == System.Net.HttpStatusCode.Unauthorized
                ? MapUnauthorizedError(upstreamCode) : "ingress_cleanup_failed");
        }
        catch (HttpRequestException exception)
        {
            throw new MediaEngineException("ingress_cleanup_failed", exception);
        }
    }

    public async Task<DesktopPublishStatus> StatusAsync(HostAuthorization authorization, string ingressId, CancellationToken cancellationToken)
    {
        using var request = CreateRequest(authorization, "status", new
        {
            ingress_id = ingressId,
            transmission_instance_id = authorization.TransmissionInstanceId,
            transmission_revision = authorization.TransmissionRevision,
        });
        HttpResponseMessage response;
        try { response = await _http.SendAsync(request, cancellationToken); }
        catch (HttpRequestException exception) { throw new MediaEngineException("ingress_status_request_failed", exception); }
        using (response)
        {
            if (!response.IsSuccessStatusCode)
            {
                var upstreamCode = await ReadErrorCodeAsync(response, cancellationToken);
                var code = response.StatusCode switch
                {
                    System.Net.HttpStatusCode.Unauthorized => MapUnauthorizedError(upstreamCode),
                    System.Net.HttpStatusCode.Forbidden => "ingress_status_forbidden",
                    System.Net.HttpStatusCode.NotFound when upstreamCode == "ingress_not_found" => "ingress_not_found",
                    System.Net.HttpStatusCode.Conflict => "transmission_changed",
                    System.Net.HttpStatusCode.TooManyRequests => "ingress_status_request_failed",
                    _ when (int)response.StatusCode >= 500 => "ingress_status_request_failed",
                    _ => "ingress_status_request_failed",
                };
                throw new MediaEngineException(code);
            }
            try
            {
                using var json = await JsonDocument.ParseAsync(
                    await response.Content.ReadAsStreamAsync(cancellationToken),
                    cancellationToken: cancellationToken);
                var state = json.RootElement.TryGetProperty("state", out var value) ? value.GetString() : null;
                if (state is not ("inactive" or "buffering" or "publishing" or "error" or "complete"))
                    throw new MediaEngineException("ingress_status_invalid");
                return new DesktopPublishStatus(state);
            }
            catch (JsonException exception)
            {
                throw new MediaEngineException("ingress_status_invalid", exception);
            }
        }
    }

    private static async Task<string?> ReadErrorCodeAsync(HttpResponseMessage response, CancellationToken cancellationToken)
    {
        try
        {
            using var json = await JsonDocument.ParseAsync(
                await response.Content.ReadAsStreamAsync(cancellationToken),
                cancellationToken: cancellationToken);
            return json.RootElement.TryGetProperty("error", out var error) ? error.GetString() : null;
        }
        catch (JsonException) { return null; }
    }

    internal static string MapUnauthorizedError(string? upstreamCode) => upstreamCode switch
    {
        "host_session_expired" => "host_authorization_expired",
        "host_session_required" => "host_authorization_required",
        _ => "host_authorization_invalid",
    };

    private HttpRequestMessage CreateRequest(HostAuthorization authorization, string action, object body)
    {
        var uri = new Uri(baseUri(), $"room/{Uri.EscapeDataString(authorization.RoomCode)}/desktop/media-publish/{action}");
        var request = new HttpRequestMessage(HttpMethod.Post, uri) { Content = JsonContent.Create(body) };
        request.Headers.Authorization = new AuthenticationHeaderValue("Bearer", authorization.Token);
        request.Headers.UserAgent.ParseAdd("SemyraDesktop/1.0");
        return request;
    }

    private static HttpClient CreateHttpClient() => new(new HttpClientHandler { AllowAutoRedirect = false })
    {
        Timeout = TimeSpan.FromSeconds(20),
    };
}
