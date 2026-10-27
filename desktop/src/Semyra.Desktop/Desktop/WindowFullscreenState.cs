using System.Windows;

namespace Semyra.Desktop.Desktop;

internal sealed class WindowFullscreenState
{
    private WindowStyle _style;
    private ResizeMode _resizeMode;
    private WindowState _windowState;
    private Rect _bounds;

    internal bool Enabled { get; private set; }

    internal bool Enter(WindowStyle style, ResizeMode resizeMode, WindowState windowState, Rect bounds)
    {
        if (Enabled) return false;
        _style = style;
        _resizeMode = resizeMode;
        _windowState = windowState;
        _bounds = bounds;
        Enabled = true;
        return true;
    }

    internal WindowFullscreenRestore? Exit()
    {
        if (!Enabled) return null;
        Enabled = false;
        return new WindowFullscreenRestore(_style, _resizeMode, _windowState, _bounds);
    }
}

internal sealed record WindowFullscreenRestore(
    WindowStyle Style,
    ResizeMode ResizeMode,
    WindowState WindowState,
    Rect Bounds);
