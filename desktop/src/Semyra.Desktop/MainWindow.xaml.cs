using System.Diagnostics;
using System.Windows;
using Microsoft.Web.WebView2.Core;
using Semyra.Desktop.Desktop;

namespace Semyra.Desktop;

public partial class MainWindow : Window
{
    private NavigationPolicy? _navigationPolicy;

    public MainWindow()
    {
        InitializeComponent();
        Loaded += OnLoaded;
    }

    private async void OnLoaded(object sender, RoutedEventArgs e)
    {
        Loaded -= OnLoaded;

        try
        {
            var configuration = SemyraWebConfiguration.Load();
            _navigationPolicy = new NavigationPolicy(configuration.BaseUri);

            var environment = await CoreWebView2Environment.CreateAsync(
                userDataFolder: configuration.UserDataFolder);
            await Browser.EnsureCoreWebView2Async(environment);

#if DEBUG
            Browser.CoreWebView2.Settings.AreDevToolsEnabled = true;
#else
            Browser.CoreWebView2.Settings.AreDevToolsEnabled = false;
#endif

            Browser.CoreWebView2.NavigationStarting += OnNavigationStarting;
            Browser.CoreWebView2.NewWindowRequested += OnNewWindowRequested;
            Browser.CoreWebView2.WebMessageReceived += OnWebMessageReceived;

            await Browser.CoreWebView2.AddScriptToExecuteOnDocumentCreatedAsync("""
                (() => {
                    if (!Object.prototype.hasOwnProperty.call(window, 'semyraDesktop')) {
                        Object.defineProperty(window, 'semyraDesktop', {
                            value: Object.freeze({ available: true, protocolVersion: 1 }),
                            writable: false,
                            configurable: false,
                            enumerable: true
                        });
                    }
                })();
                """);

            Browser.CoreWebView2.Navigate(configuration.BaseUri.AbsoluteUri);
        }
        catch (WebView2RuntimeNotFoundException)
        {
            ShowFatalError("O Microsoft Edge WebView2 Runtime não está instalado. Instale-o e abra o Semyra novamente.");
        }
        catch (InvalidOperationException exception)
        {
            ShowFatalError(exception.Message);
        }
        catch (Exception exception)
        {
            ShowFatalError($"Não foi possível iniciar o Semyra Desktop. {exception.Message}");
        }
    }

    private void OnNavigationStarting(object? sender, CoreWebView2NavigationStartingEventArgs e)
    {
        if (_navigationPolicy is null || !Uri.TryCreate(e.Uri, UriKind.Absolute, out var uri))
        {
            e.Cancel = true;
            return;
        }

        if (_navigationPolicy.IsAllowed(uri))
        {
            return;
        }

        e.Cancel = true;
        OpenExternal(uri);
    }

    private void OnNewWindowRequested(object? sender, CoreWebView2NewWindowRequestedEventArgs e)
    {
        e.Handled = true;
        if (_navigationPolicy is null || !Uri.TryCreate(e.Uri, UriKind.Absolute, out var uri))
        {
            return;
        }

        if (_navigationPolicy.IsAllowed(uri))
        {
            Browser.CoreWebView2.Navigate(uri.AbsoluteUri);
            return;
        }

        OpenExternal(uri);
    }

    private void OnWebMessageReceived(object? sender, CoreWebView2WebMessageReceivedEventArgs e)
    {
        if (_navigationPolicy is null
            || !Uri.TryCreate(e.Source, UriKind.Absolute, out var source)
            || !_navigationPolicy.IsAllowed(source))
        {
            return;
        }

        if (DesktopBridge.TryHandle(e.WebMessageAsJson, out var response))
        {
            Browser.CoreWebView2.PostWebMessageAsJson(response);
        }
    }

    private static void OpenExternal(Uri uri)
    {
        if (!string.Equals(uri.Scheme, Uri.UriSchemeHttp, StringComparison.OrdinalIgnoreCase)
            && !string.Equals(uri.Scheme, Uri.UriSchemeHttps, StringComparison.OrdinalIgnoreCase))
        {
            return;
        }

        try
        {
            Process.Start(new ProcessStartInfo(uri.AbsoluteUri) { UseShellExecute = true });
        }
        catch
        {
            // A falha do navegador externo não deve derrubar a aplicação desktop.
        }
    }

    private void ShowFatalError(string message)
    {
        MessageBox.Show(this, message, "Semyra", MessageBoxButton.OK, MessageBoxImage.Error);
        Application.Current.Shutdown();
    }
}
