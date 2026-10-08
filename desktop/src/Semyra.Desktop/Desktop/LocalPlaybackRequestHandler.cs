using Semyra.Desktop.Host;
using Semyra.Desktop.Media;

namespace Semyra.Desktop.Desktop;

internal sealed class LocalPlaybackRequestHandler(Uri baseUri, HostEngine hostEngine)
{
    internal LocalPlaybackRequestKind Classify(string requestUri, string method)
    {
        if (!Uri.TryCreate(requestUri, UriKind.Absolute, out var uri)
            || !SameOrigin(baseUri, uri))
        {
            return LocalPlaybackRequestKind.NotPlayback;
        }
        return hostEngine.ClassifyLocalPlaybackRequest(uri, method);
    }

    internal bool TryRead(string requestUri, string method, out LocalPlaybackResource? resource)
    {
        resource = null;
        if (!Uri.TryCreate(requestUri, UriKind.Absolute, out var uri)
            || !SameOrigin(baseUri, uri))
        {
            return false;
        }
        return hostEngine.TryReadLocalPlayback(uri, method, out resource);
    }

    internal async Task<LocalPlaybackResource?> ReadAsync(string requestUri, string method, CancellationToken cancellationToken = default)
    {
        if (!Uri.TryCreate(requestUri, UriKind.Absolute, out var uri) || !SameOrigin(baseUri, uri))
        {
            return null;
        }
        return await hostEngine.ReadLocalPlaybackAsync(uri, method, cancellationToken);
    }

    private static bool SameOrigin(Uri left, Uri right) =>
        string.Equals(left.Scheme, right.Scheme, StringComparison.OrdinalIgnoreCase)
        && string.Equals(left.Host, right.Host, StringComparison.OrdinalIgnoreCase)
        && left.Port == right.Port;
}
