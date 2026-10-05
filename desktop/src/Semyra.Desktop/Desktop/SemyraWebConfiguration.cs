using System.IO;

namespace Semyra.Desktop.Desktop;

public sealed record SemyraWebConfiguration(Uri BaseUri, string UserDataFolder)
{
    public static SemyraWebConfiguration Load()
    {
        var configuredUrl = Environment.GetEnvironmentVariable("SEMYRA_DESKTOP_URL");

#if DEBUG
        var value = string.IsNullOrWhiteSpace(configuredUrl) ? "http://localhost:8010/" : configuredUrl.Trim();
        const bool allowLocalHttp = true;
#else
        if (string.IsNullOrWhiteSpace(configuredUrl))
        {
            throw new InvalidOperationException(
                "Configure SEMYRA_DESKTOP_URL com a URL HTTPS do Semyra antes de iniciar a aplicação.");
        }

        var value = configuredUrl.Trim();
        const bool allowLocalHttp = false;
#endif

        if (!NavigationPolicy.TryCreateBaseUri(value, allowLocalHttp, out var baseUri) || baseUri is null)
        {
            throw new InvalidOperationException(
                "SEMYRA_DESKTOP_URL é inválida. Use HTTPS; em Debug, HTTP é aceito somente para localhost ou 127.0.0.1.");
        }

        var userDataFolder = Path.Combine(
            Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData),
            "Semyra",
            "WebView2");

        return new SemyraWebConfiguration(baseUri, userDataFolder);
    }
}
