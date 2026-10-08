using System.Diagnostics;
using System.Windows;
using Microsoft.Web.WebView2.Core;
using Microsoft.Win32;
using Semyra.Desktop.Desktop;
using Semyra.Desktop.Host;
using Semyra.Desktop.Iptv;
using Semyra.Desktop.Media;

namespace Semyra.Desktop;

public partial class MainWindow : Window
{
    private NavigationPolicy? _navigationPolicy;
    private readonly HostEngine _hostEngine;

    public MainWindow()
    {
        _hostEngine = new HostEngine();
        _hostEngine.MediaStateChanged += OnMediaStateChanged;
        InitializeComponent();
        Loaded += OnLoaded;
    }

    private async void OnLoaded(object sender, RoutedEventArgs e)
    {
        Loaded -= OnLoaded;

        try
        {
            _hostEngine.Start();
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

    private async void OnWebMessageReceived(object? sender, CoreWebView2WebMessageReceivedEventArgs e)
    {
        if (_navigationPolicy is null
            || !Uri.TryCreate(e.Source, UriKind.Absolute, out var source)
            || !_navigationPolicy.IsAllowed(source))
        {
            return;
        }

        var result = await DesktopBridge.TryHandleAsync(
            e.WebMessageAsJson,
            _hostEngine,
            PickM3uFile);
        if (result.Handled)
        {
            Browser.CoreWebView2.PostWebMessageAsJson(result.Response);
        }
    }

    private string? PickM3uFile()
    {
        var dialog = new OpenFileDialog
        {
            Title = "Selecionar playlist IPTV",
            Filter = "Playlists M3U (*.m3u;*.m3u8)|*.m3u;*.m3u8|Todos os arquivos (*.*)|*.*",
            CheckFileExists = true,
            Multiselect = false,
        };
        return dialog.ShowDialog(this) == true ? dialog.FileName : null;
    }

    protected override void OnClosed(EventArgs e)
    {
        _hostEngine.MediaStateChanged -= OnMediaStateChanged;
        _hostEngine.Dispose();
        base.OnClosed(e);
    }

    private void OnMediaStateChanged(object? sender, MediaSnapshot snapshot)
    {
        Dispatcher.InvokeAsync(() =>
        {
            if (Browser.CoreWebView2 is not null)
            {
                Browser.CoreWebView2.PostWebMessageAsJson(DesktopBridge.CreateMediaStateEvent(snapshot, _hostEngine.CurrentAccountContextId));
            }
        });
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
