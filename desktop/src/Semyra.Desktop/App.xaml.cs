using System.Windows;

namespace Semyra.Desktop;

public partial class App : Application
{
    // Keep CsWinRT in self-contained publishes. WebView2CompositionControl loads
    // this runtime dynamically, so the publish dependency analysis cannot infer it.
    private static readonly Type WinRtRuntimeAnchor = typeof(WinRT.IWinRTObject);
}
