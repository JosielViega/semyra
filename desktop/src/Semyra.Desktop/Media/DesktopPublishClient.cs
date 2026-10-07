using System.Net.Http.Headers;
using System.Net.Http.Json;
using System.Net.Http;
using System.Text.Json;
using Semyra.Desktop.Desktop;
using Semyra.Desktop.Host;

namespace Semyra.Desktop.Media;

internal sealed record DesktopPublishLease(string IngressId, Uri WhipEndpoint);

internal interface IDesktopPublishClient
{
    Task<DesktopPublishLease> StartAsync(HostAuthorization authorization, CancellationToken cancellationToken);
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
            throw new MediaEngineException(response.StatusCode == System.Net.HttpStatusCode.Unauthorized
                ? "host_authorization_expired" : "ingress_create_failed");
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
        using var response = await _http.SendAsync(request, cancellationToken);
    }

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
