using System.IO;

namespace Semyra.Desktop.Desktop;

public sealed record SemyraWebConfiguration(Uri BaseUri, string UserDataFolder)
{
    public static SemyraWebConfiguration Load()
    {
        var configuredUrl = Environment.GetEnvironmentVariable("SEMYRA_DESKTOP_URL");

#if DEBUG
        const string defaultUrl = "http://localhost:8010/";
        const bool allowLocalHttp = true;
#else
        const string? defaultUrl = null;
        const bool allowLocalHttp = false;
#endif

        return Create(
            configuredUrl,
            Environment.GetEnvironmentVariable("WEBVIEW2_USER_DATA_FOLDER"),
            Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData),
            allowLocalHttp,
            defaultUrl);
    }

    internal static SemyraWebConfiguration Create(
        string? configuredUrl,
        string? configuredFolder,
        string localApplicationData,
        bool allowLocalHttp,
        string? defaultUrl)
    {
        var value = string.IsNullOrWhiteSpace(configuredUrl) ? defaultUrl : configuredUrl.Trim();
        if (string.IsNullOrWhiteSpace(value))
        {
            throw new InvalidOperationException(
                "Configure SEMYRA_DESKTOP_URL com a URL HTTPS do Semyra antes de iniciar a aplicação.");
        }

        if (!NavigationPolicy.TryCreateBaseUri(value, allowLocalHttp, out var baseUri) || baseUri is null)
        {
            throw new InvalidOperationException(
                "SEMYRA_DESKTOP_URL é inválida. Use HTTPS; em Debug, HTTP é aceito somente para localhost ou 127.0.0.1.");
        }

        var userDataFolder = ResolveUserDataFolder(
            configuredFolder,
            localApplicationData);

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
