using Semyra.Desktop.Desktop;

namespace Semyra.Desktop.Tests;

public sealed class SemyraWebConfigurationTests
{
    [Fact]
    public void UsesLocalDefaultOnlyWhenLocalHttpIsEnabled()
    {
        var result = SemyraWebConfiguration.Create(
            configuredUrl: null,
            configuredFolder: "C:\\profiles\\local",
            localApplicationData: "C:\\local",
            allowLocalHttp: true,
            defaultUrl: "http://localhost:8010/");

        Assert.Equal("http://localhost:8010/", result.BaseUri.AbsoluteUri);
    }

    [Fact]
    public void AcceptsExplicitProductionHttpsOrigin()
    {
        var result = SemyraWebConfiguration.Create(
            configuredUrl: "https://semyra.jzlcode.com.br/",
            configuredFolder: "C:\\profiles\\online",
            localApplicationData: "C:\\local",
            allowLocalHttp: false,
            defaultUrl: null);

        Assert.Equal("https://semyra.jzlcode.com.br/", result.BaseUri.AbsoluteUri);
    }

    [Fact]
    public void ReleaseConfigurationRequiresExplicitUrl()
    {
        Assert.Throws<InvalidOperationException>(() => SemyraWebConfiguration.Create(
            configuredUrl: null,
            configuredFolder: "C:\\profiles\\online",
            localApplicationData: "C:\\local",
            allowLocalHttp: false,
            defaultUrl: null));
    }

    [Theory]
    [InlineData("http://semyra.jzlcode.com.br/")]
    [InlineData("http://192.168.0.10/")]
    public void RejectsRemoteHttpForOnlineConfiguration(string url)
    {
        Assert.Throws<InvalidOperationException>(() => SemyraWebConfiguration.Create(
            configuredUrl: url,
            configuredFolder: "C:\\profiles\\online",
            localApplicationData: "C:\\local",
            allowLocalHttp: false,
            defaultUrl: null));
    }

    [Fact]
    public void KeepsLocalAndOnlineWebViewProfilesIsolated()
    {
        var local = SemyraWebConfiguration.Create(
            "http://127.0.0.1:8134/", "C:\\profiles\\local", "C:\\fallback", true, null);
        var online = SemyraWebConfiguration.Create(
            "https://semyra.jzlcode.com.br/", "C:\\profiles\\online", "C:\\fallback", false, null);

        Assert.NotEqual(local.UserDataFolder, online.UserDataFolder);
        Assert.Equal(Path.GetFullPath("C:\\profiles\\local"), local.UserDataFolder);
        Assert.Equal(Path.GetFullPath("C:\\profiles\\online"), online.UserDataFolder);
    }

    [Fact]
    public void UsesLauncherSpecificAbsoluteWebViewProfile()
    {
        var configured = Path.Combine(Path.GetTempPath(), "semyra-11n", "webview2-profile");

        var result = SemyraWebConfiguration.ResolveUserDataFolder(configured, "C:\\fallback");

        Assert.Equal(Path.GetFullPath(configured), result);
    }

    [Fact]
    public void FallsBackToStableLocalApplicationDataProfile()
    {
        Assert.Equal(
            Path.Combine("C:\\local", "Semyra", "WebView2"),
            SemyraWebConfiguration.ResolveUserDataFolder(null, "C:\\local"));
    }

    [Fact]
    public void RejectsRelativeProfilePath()
    {
        Assert.Throws<InvalidOperationException>(() =>
            SemyraWebConfiguration.ResolveUserDataFolder("relative-profile", "C:\\local"));
    }
}
