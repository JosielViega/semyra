using System.Diagnostics;
using System.ComponentModel;
using System.IO;
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
    private LocalPlaybackRequestHandler? _playbackRequests;
    private readonly HostEngine _hostEngine;
    private bool _shutdownStarted;
    private bool _shutdownComplete;

    public MainWindow()
    {
        _hostEngine = new HostEngine();
        _hostEngine.MediaStateChanged += OnMediaStateChanged;
        InitializeComponent();
        Loaded += OnLoaded;
        Closing += OnClosing;
    }

    private async void OnLoaded(object sender, RoutedEventArgs e)
    {
        Loaded -= OnLoaded;

        try
        {
            _hostEngine.Start();
            var configuration = SemyraWebConfiguration.Load();
            _navigationPolicy = new NavigationPolicy(configuration.BaseUri);
            _playbackRequests = new LocalPlaybackRequestHandler(configuration.BaseUri, _hostEngine);

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
            var origin = configuration.BaseUri.GetLeftPart(UriPartial.Authority);
            Browser.CoreWebView2.AddWebResourceRequestedFilter(
                $"{origin}/__desktop/playback/*",
                CoreWebView2WebResourceContext.All);
            Browser.CoreWebView2.WebResourceRequested += OnWebResourceRequested;

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

    private async void OnWebResourceRequested(object? sender, CoreWebView2WebResourceRequestedEventArgs e)
    {
        var classification = _playbackRequests?.Classify(e.Request.Uri, e.Request.Method)
            ?? LocalPlaybackRequestKind.NotPlayback;
        if (_playbackRequests is null || classification == LocalPlaybackRequestKind.NotPlayback)
        {
            return;
        }

        var deferral = e.GetDeferral();
        try
        {
            var resource = await _playbackRequests.ReadAsync(e.Request.Uri, e.Request.Method);
            if (resource is not null)
            {
                var body = new MemoryStream(resource.Content, writable: false);
                e.Response = Browser.CoreWebView2.Environment.CreateWebResourceResponse(
                    body, 200, "OK",
                    $"Content-Type: {resource.ContentType}\r\nCache-Control: no-store\r\nX-Content-Type-Options: nosniff");
                return;
            }

            e.Response = Browser.CoreWebView2.Environment.CreateWebResourceResponse(
                Stream.Null, 404, "Not Found",
                "Cache-Control: no-store\r\nX-Content-Type-Options: nosniff");
        }
        finally
        {
            deferral.Complete();
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
            try
            {
                Browser.CoreWebView2.PostWebMessageAsJson(result.Response);
            }
            catch (InvalidOperationException)
            {
                // A navegação de logout pode concluir antes do cleanup nativo.
            }
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

    private async void OnClosing(object? sender, CancelEventArgs e)
    {
        if (_shutdownComplete)
        {
            return;
        }

        e.Cancel = true;
        if (_shutdownStarted)
        {
            return;
        }
        _shutdownStarted = true;

        _hostEngine.MediaStateChanged -= OnMediaStateChanged;
        if (Browser.CoreWebView2 is not null)
        {
            Browser.CoreWebView2.NavigationStarting -= OnNavigationStarting;
            Browser.CoreWebView2.NewWindowRequested -= OnNewWindowRequested;
            Browser.CoreWebView2.WebMessageReceived -= OnWebMessageReceived;
            Browser.CoreWebView2.WebResourceRequested -= OnWebResourceRequested;
        }
        _playbackRequests = null;
        try
        {
            await _hostEngine.DisposeAsync();
        }
        finally
        {
            _shutdownComplete = true;
            Close();
        }
    }

    protected override void OnClosed(EventArgs e)
    {
        Closing -= OnClosing;
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
