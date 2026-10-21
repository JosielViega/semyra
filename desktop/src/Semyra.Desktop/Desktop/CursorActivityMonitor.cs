using System.Runtime.InteropServices;

namespace Semyra.Desktop.Desktop;

internal readonly record struct CursorScreenPoint(int X, int Y);

internal interface ICursorActivitySource
{
    bool TryGetPosition(out CursorScreenPoint position);
    bool IsForeground(nint windowHandle);
    bool IsInsideWindow(nint windowHandle, CursorScreenPoint position);
}

internal sealed class Win32CursorActivitySource : ICursorActivitySource
{
    public bool TryGetPosition(out CursorScreenPoint position)
    {
        if (!GetCursorPos(out var point))
        {
            position = default;
            return false;
        }
        position = new CursorScreenPoint(point.X, point.Y);
        return true;
    }

    public bool IsForeground(nint windowHandle) =>
        windowHandle != 0 && GetForegroundWindow() == windowHandle;

    public bool IsInsideWindow(nint windowHandle, CursorScreenPoint position) =>
        windowHandle != 0
        && GetWindowRect(windowHandle, out var rectangle)
        && position.X >= rectangle.Left
        && position.X < rectangle.Right
        && position.Y >= rectangle.Top
        && position.Y < rectangle.Bottom;

    [DllImport("user32.dll")]
    [return: MarshalAs(UnmanagedType.Bool)]
    private static extern bool GetCursorPos(out NativePoint point);

    [DllImport("user32.dll")]
    private static extern nint GetForegroundWindow();

    [DllImport("user32.dll")]
    [return: MarshalAs(UnmanagedType.Bool)]
    private static extern bool GetWindowRect(nint windowHandle, out NativeRect rectangle);

    [StructLayout(LayoutKind.Sequential)]
    private struct NativePoint
    {
        internal int X;
        internal int Y;
    }

    [StructLayout(LayoutKind.Sequential)]
    private struct NativeRect
    {
        internal int Left;
        internal int Top;
        internal int Right;
        internal int Bottom;
    }
}

internal sealed class CursorActivityMonitor : IDisposable
{
    internal const int PollIntervalMilliseconds = 100;

    private readonly object _gate = new();
    private readonly ICursorActivitySource _source;
    private readonly bool _scheduleAutomatically;
    private Timer? _timer;
    private CursorScreenPoint? _previous;
    private nint _windowHandle;
    private bool _running;
    private bool _disposed;

    internal CursorActivityMonitor(
        ICursorActivitySource source,
        bool scheduleAutomatically = true)
    {
        _source = source;
        _scheduleAutomatically = scheduleAutomatically;
    }

    internal event EventHandler? Activity;
    internal bool IsRunning { get { lock (_gate) return _running; } }

    internal void Start(nint windowHandle)
    {
        lock (_gate)
        {
            ObjectDisposedException.ThrowIf(_disposed, this);
            if (_running && _windowHandle == windowHandle) return;
            StopCore();
            if (windowHandle == 0) return;
            _windowHandle = windowHandle;
            _previous = _source.TryGetPosition(out var position) ? position : null;
            _running = true;
            if (_scheduleAutomatically)
            {
                _timer = new Timer(
                    static state => ((CursorActivityMonitor)state!).PollNow(),
                    this,
                    PollIntervalMilliseconds,
                    PollIntervalMilliseconds);
            }
        }
    }

    internal void Stop()
    {
        lock (_gate) StopCore();
    }

    internal void PollNow()
    {
        lock (_gate)
        {
            if (!_running || !_source.TryGetPosition(out var current)) return;
            var previous = _previous;
            _previous = current;
            if (previous is null || previous.Value == current) return;
            if (!_source.IsForeground(_windowHandle) || !_source.IsInsideWindow(_windowHandle, current)) return;
            Activity?.Invoke(this, EventArgs.Empty);
        }
    }

    public void Dispose()
    {
        lock (_gate)
        {
            if (_disposed) return;
            StopCore();
            _disposed = true;
        }
    }

    private void StopCore()
    {
        _timer?.Dispose();
        _timer = null;
        _previous = null;
        _windowHandle = 0;
        _running = false;
    }
}

internal sealed class PlayerCursorActivityController : IDisposable
{
    private readonly CursorActivityMonitor _monitor;
    private readonly Func<nint> _windowHandle;
    private readonly Action<bool> _setCursorHidden;
    private bool _controlsHidden;
    private bool _fullscreen;
    private bool _playbackVisible;
    private bool _windowActive = true;
    private bool _cursorHidden;
    private bool _disposed;

    internal PlayerCursorActivityController(
        CursorActivityMonitor monitor,
        Func<nint> windowHandle,
        Action<bool>? setCursorHidden = null)
    {
        _monitor = monitor;
        _windowHandle = windowHandle;
        _setCursorHidden = setCursorHidden ?? (_ => { });
        _monitor.Activity += OnActivity;
    }

    internal event EventHandler? Activity;
    internal bool IsRunning => _monitor.IsRunning;

    internal void SetControlsHidden(bool hidden)
    {
        if (_disposed) return;
        _controlsHidden = hidden;
        Update();
    }

    internal void SetFullscreen(bool fullscreen)
    {
        if (_disposed) return;
        _fullscreen = fullscreen;
        Update();
    }

    internal void SetPlaybackVisible(bool visible)
    {
        if (_disposed) return;
        _playbackVisible = visible;
        Update();
    }

    internal void SetWindowActive(bool active)
    {
        if (_disposed) return;
        _windowActive = active;
        Update();
    }

    internal void Reset()
    {
        if (_disposed) return;
        _controlsHidden = false;
        _fullscreen = false;
        _playbackVisible = false;
        _monitor.Stop();
        SetCursorHidden(false);
    }

    public void Dispose()
    {
        if (_disposed) return;
        SetCursorHidden(false);
        _disposed = true;
        _monitor.Activity -= OnActivity;
        _monitor.Dispose();
    }

    private void Update()
    {
        var shouldHideCursor = _controlsHidden && _fullscreen && _playbackVisible && _windowActive;
        SetCursorHidden(shouldHideCursor);
        if (shouldHideCursor)
        {
            _monitor.Start(_windowHandle());
        }
        else
        {
            _monitor.Stop();
        }
    }

    private void SetCursorHidden(bool hidden)
    {
        if (_cursorHidden == hidden) return;
        _cursorHidden = hidden;
        _setCursorHidden(hidden);
    }

    private void OnActivity(object? sender, EventArgs e) => Activity?.Invoke(this, EventArgs.Empty);
}
