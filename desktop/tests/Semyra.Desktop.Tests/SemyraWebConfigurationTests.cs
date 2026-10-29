using Semyra.Desktop.Desktop;

namespace Semyra.Desktop.Tests;

public sealed class SemyraWebConfigurationTests
{
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
