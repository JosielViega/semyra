namespace Semyra.Desktop.Desktop;

public sealed class NavigationPolicy
{
    private readonly Uri _origin;

    public NavigationPolicy(Uri baseUri)
    {
        ArgumentNullException.ThrowIfNull(baseUri);
        _origin = new Uri(baseUri.GetLeftPart(UriPartial.Authority));
    }

    public bool IsAllowed(Uri candidate)
    {
        return candidate.IsAbsoluteUri
            && string.Equals(candidate.Scheme, _origin.Scheme, StringComparison.OrdinalIgnoreCase)
            && string.Equals(candidate.Host, _origin.Host, StringComparison.OrdinalIgnoreCase)
            && candidate.Port == _origin.Port;
    }

    public static bool TryCreateBaseUri(string? value, bool allowLocalHttp, out Uri? uri)
    {
        uri = null;
        if (!Uri.TryCreate(value, UriKind.Absolute, out var candidate)
            || !IsWebScheme(candidate.Scheme)
            || !string.IsNullOrEmpty(candidate.UserInfo))
        {
            return false;
        }

        if (candidate.Scheme == Uri.UriSchemeHttp
            && (!allowLocalHttp || !IsLocalDevelopmentHost(candidate.Host)))
        {
            return false;
        }

        uri = candidate;
        return true;
    }

    private static bool IsLocalDevelopmentHost(string host)
    {
        return string.Equals(host, "localhost", StringComparison.OrdinalIgnoreCase)
            || string.Equals(host, "127.0.0.1", StringComparison.Ordinal);
    }

    private static bool IsWebScheme(string scheme)
    {
        return string.Equals(scheme, Uri.UriSchemeHttp, StringComparison.OrdinalIgnoreCase)
            || string.Equals(scheme, Uri.UriSchemeHttps, StringComparison.OrdinalIgnoreCase);
    }
}
