using System.Diagnostics;
using System.ComponentModel;
using System.Windows;
using System.Windows.Input;
using System.Windows.Interop;
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
    private readonly PlayerCursorActivityController _cursorActivity;
    private MpvGeometry? _mpvGeometry;
    private bool _mpvVisible;
    private bool _nativeVideoFullscreen;
    private WindowStyle _savedWindowStyle;
    private ResizeMode _savedResizeMode;
    private WindowState _savedWindowState;
    private Rect _savedWindowBounds;
    private bool _shutdownStarted;
    private bool _shutdownComplete;

    public MainWindow()
    {
        _hostEngine = new HostEngine();
        _cursorActivity = new PlayerCursorActivityController(
            new CursorActivityMonitor(new Win32CursorActivitySource()),
            () => new WindowInteropHelper(this).Handle,
            hidden => NativePlayerHost.SetCursorHidden(hidden));
        _cursorActivity.Activity += OnCursorActivity;
        _hostEngine.MediaStateChanged += OnMediaStateChanged;
        _hostEngine.VodProgressChanged += OnVodProgressChanged;
        _hostEngine.NativeVideoGeometryChanged += OnNativeVideoGeometryChanged;
        _hostEngine.NativeVideoVisibilityChanged += OnNativeVideoVisibilityChanged;
        _hostEngine.NativeVideoFullscreenChanged += OnNativeVideoFullscreenChanged;
        InitializeComponent();
        Loaded += OnLoaded;
        Closing += OnClosing;
        Activated += OnActivated;
        Deactivated += OnDeactivated;
        SizeChanged += OnWindowSizeChanged;
        PreviewKeyDown += OnPreviewKeyDown;
    }

    private async void OnLoaded(object sender, RoutedEventArgs e)
    {
        Loaded -= OnLoaded;

        try
        {
            _hostEngine.SetNativeVideoHost(NativePlayerHost.HostHandle);
            NativePlayerHost.SetPlaybackVisible(false);
            NativePlayerHost.Visibility = Visibility.Collapsed;
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
            _cursorActivity.Reset();
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

        if (DesktopMessage.TryReadPlayerControlsVisibility(e.WebMessageAsJson, out var hidden))
        {
            _cursorActivity.SetControlsHidden(hidden);
            return;
        }

        if (DesktopMessage.TryReadRequest(e.WebMessageAsJson, out var request)
            && request.Type == DesktopMessage.AccountClearType)
        {
            _cursorActivity.Reset();
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
        _hostEngine.VodProgressChanged -= OnVodProgressChanged;
        _hostEngine.NativeVideoGeometryChanged -= OnNativeVideoGeometryChanged;
        _hostEngine.NativeVideoVisibilityChanged -= OnNativeVideoVisibilityChanged;
        _hostEngine.NativeVideoFullscreenChanged -= OnNativeVideoFullscreenChanged;
        _cursorActivity.Activity -= OnCursorActivity;
        _cursorActivity.Dispose();
        if (Browser.CoreWebView2 is not null)
        {
            Browser.CoreWebView2.NavigationStarting -= OnNavigationStarting;
            Browser.CoreWebView2.NewWindowRequested -= OnNewWindowRequested;
            Browser.CoreWebView2.WebMessageReceived -= OnWebMessageReceived;
        }
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
        Activated -= OnActivated;
        Deactivated -= OnDeactivated;
        SizeChanged -= OnWindowSizeChanged;
        PreviewKeyDown -= OnPreviewKeyDown;
        base.OnClosed(e);
    }

    private void OnPreviewKeyDown(object sender, KeyEventArgs e)
    {
        if (!_nativeVideoFullscreen || e.Key != Key.Escape) return;
        var sessionId = _hostEngine.IptvMediaSnapshot().SessionId;
        if (sessionId is null) return;
        _hostEngine.SetMpvFullscreen(sessionId, false);
        e.Handled = true;
    }

    private void OnWindowSizeChanged(object sender, SizeChangedEventArgs e) => ApplyNativeVideoGeometry();

    private void OnActivated(object? sender, EventArgs e) => _cursorActivity.SetWindowActive(true);

    private void OnDeactivated(object? sender, EventArgs e) => _cursorActivity.SetWindowActive(false);

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

    private void OnVodProgressChanged(object? sender, IptvVodProgressChanged progress)
    {
        Dispatcher.InvokeAsync(() =>
        {
            if (Browser.CoreWebView2 is not null)
            {
                Browser.CoreWebView2.PostWebMessageAsJson(
                    DesktopBridge.CreateVodProgressChangedEvent(progress, _hostEngine.CurrentAccountContextId));
            }
        });
    }

    private void OnCursorActivity(object? sender, EventArgs e)
    {
        RunOnUi(() =>
        {
            if (_cursorActivity.IsRunning && Browser.CoreWebView2 is not null)
            {
                ForwardPlayerUserActivity(Browser.CoreWebView2.PostWebMessageAsJson);
            }
        });
    }

    internal static void ForwardPlayerUserActivity(Action<string> postMessage) =>
        postMessage(DesktopBridge.CreatePlayerUserActivityEvent());

    private void OnNativeVideoGeometryChanged(object? sender, MpvGeometry geometry)
    {
        RunOnUi(() =>
        {
            _mpvGeometry = geometry;
            ApplyNativeVideoGeometry();
        });
    }

    private void OnNativeVideoVisibilityChanged(object? sender, bool visible)
    {
        RunOnUi(() =>
        {
            _mpvVisible = visible;
            _cursorActivity.SetPlaybackVisible(visible);
            if (!visible) _mpvGeometry = null;
            ApplyNativeVideoGeometry();
        });
    }

    private void OnNativeVideoFullscreenChanged(object? sender, bool fullscreen)
    {
        RunOnUi(() => ApplyNativeFullscreen(fullscreen));
    }

    private void ApplyNativeFullscreen(bool fullscreen)
    {
        _cursorActivity.SetFullscreen(fullscreen);
        if (_nativeVideoFullscreen == fullscreen) return;
        if (fullscreen)
        {
            _savedWindowStyle = WindowStyle;
            _savedResizeMode = ResizeMode;
            _savedWindowState = WindowState;
            _savedWindowBounds = RestoreBounds;
            WindowState = WindowState.Normal;
            WindowStyle = WindowStyle.None;
            ResizeMode = ResizeMode.NoResize;
            WindowState = WindowState.Maximized;
        }
        else
        {
            WindowState = WindowState.Normal;
            WindowStyle = _savedWindowStyle;
            ResizeMode = _savedResizeMode;
            if (_savedWindowState == WindowState.Normal && !_savedWindowBounds.IsEmpty)
            {
                Left = _savedWindowBounds.Left;
                Top = _savedWindowBounds.Top;
                Width = _savedWindowBounds.Width;
                Height = _savedWindowBounds.Height;
            }
            else
            {
                WindowState = _savedWindowState;
            }
        }
        _nativeVideoFullscreen = fullscreen;
        ApplyNativeVideoGeometry();
    }

    private void ApplyNativeVideoGeometry()
    {
        var layout = _mpvVisible && _mpvGeometry?.Visible == true
            ? NativeVideoLayout.Calculate(_mpvGeometry, Browser.ActualWidth, Browser.ActualHeight)
            : null;
        if (layout is null)
        {
            NativePlayerHost.SetPlaybackVisible(false);
            NativePlayerHost.Visibility = Visibility.Collapsed;
            NativePlayerHost.Width = 1;
            NativePlayerHost.Height = 1;
            NativePlayerHost.Margin = new Thickness(-10000, 0, 0, 0);
            return;
        }
        NativePlayerHost.Visibility = Visibility.Visible;
        NativePlayerHost.Width = layout.Width;
        NativePlayerHost.Height = layout.Height;
        NativePlayerHost.Margin = new Thickness(layout.X, layout.Y, 0, 0);
        NativePlayerHost.UpdateLayout();
        NativePlayerHost.SetPlaybackVisible(true);
        NativePlayerHost.PromoteForPlayback();
        NativePlayerHost.ReapplyHiddenCursor();
    }

    private void RunOnUi(Action action)
    {
        void RunUnlessClosing()
        {
            if (!_shutdownStarted) action();
        }

        if (Dispatcher.CheckAccess()) RunUnlessClosing();
        else Dispatcher.InvokeAsync(RunUnlessClosing);
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
