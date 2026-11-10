using System.IO;

namespace Semyra.Desktop.Desktop;

public sealed record SemyraWebConfiguration(Uri BaseUri, string UserDataFolder)
{
    public const string OfficialReleaseUrl = "https://semyra.jzlcode.com.br/";
    internal const string LocalDevelopmentUrl = "http://localhost:8010/";

    public static SemyraWebConfiguration Load()
    {
        var configuredUrl = Environment.GetEnvironmentVariable("SEMYRA_DESKTOP_URL");

#if DEBUG
        return CreateForBuild(
            developmentBuild: true,
            configuredUrl,
            Environment.GetEnvironmentVariable("WEBVIEW2_USER_DATA_FOLDER"),
            Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData));
#else
        return CreateForBuild(
            developmentBuild: false,
            configuredUrl,
            Environment.GetEnvironmentVariable("WEBVIEW2_USER_DATA_FOLDER"),
            Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData));
#endif
    }

    internal static SemyraWebConfiguration CreateForBuild(
        bool developmentBuild,
        string? configuredUrl,
        string? configuredFolder,
        string localApplicationData) => Create(
            developmentBuild ? configuredUrl : null,
            configuredFolder,
            localApplicationData,
            allowLocalHttp: developmentBuild,
            defaultUrl: developmentBuild ? LocalDevelopmentUrl : OfficialReleaseUrl);

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
                "A origem web do Semyra Desktop não está configurada.");
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
