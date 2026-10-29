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

        var userDataFolder = ResolveUserDataFolder(
            Environment.GetEnvironmentVariable("WEBVIEW2_USER_DATA_FOLDER"),
            Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData));

        return new SemyraWebConfiguration(baseUri, userDataFolder);
    }

    internal static string ResolveUserDataFolder(string? configuredFolder, string localApplicationData)
    {
        if (string.IsNullOrWhiteSpace(configuredFolder))
        {
            return Path.Combine(localApplicationData, "Semyra", "WebView2");
        }
        var value = configuredFolder.Trim();
        if (!Path.IsPathFullyQualified(value))
        {
            throw new InvalidOperationException("WEBVIEW2_USER_DATA_FOLDER deve ser um caminho absoluto.");
        }
        try
        {
            return Path.GetFullPath(value);
        }
        catch (Exception exception) when (exception is ArgumentException or NotSupportedException or PathTooLongException)
        {
            throw new InvalidOperationException("WEBVIEW2_USER_DATA_FOLDER é inválido.", exception);
        }
    }
}
