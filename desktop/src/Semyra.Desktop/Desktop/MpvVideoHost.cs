using System.Runtime.InteropServices;
using System.Windows.Interop;

namespace Semyra.Desktop.Desktop;

internal sealed class MpvVideoHost : HwndHost
{
    internal const uint PlaybackZOrderFlags = 0x0002 | 0x0001 | 0x0010 | 0x0040;
    private const int WmSetCursor = 0x0020;
    internal static nint PlaybackInsertAfter => 0;
    private nint _handle;
    private readonly NativeCursorState _cursor = new(new Win32NativeCursorApi());
    internal nint HostHandle => _handle;

    internal NativeVideoWindowSnapshot Snapshot()
    {
        if (_handle == 0 || !IsWindow(_handle)) return NativeVideoWindowSnapshot.Missing;
        GetWindowRect(_handle, out var window);
        GetClientRect(_handle, out var client);
        return new NativeVideoWindowSnapshot(
            true,
            IsWindowVisible(_handle),
            GetParent(_handle) != 0,
            window.Left,
            window.Top,
            Math.Max(0, window.Right - window.Left),
            Math.Max(0, window.Bottom - window.Top),
            Math.Max(0, client.Right - client.Left),
            Math.Max(0, client.Bottom - client.Top));
    }

    internal void SetPlaybackVisible(bool visible)
    {
        if (_handle != 0 && IsWindow(_handle)) ShowWindow(_handle, visible ? 5 : 0);
    }

    internal void SetCursorHidden(bool hidden)
    {
        _cursor.SetHidden(hidden);
    }

    internal void ReapplyHiddenCursor() => _cursor.ReapplyIfHidden();

    internal bool PromoteForPlayback()
    {
        if (_handle == 0 || !IsWindow(_handle)) return false;
        return SetWindowPos(_handle, PlaybackInsertAfter, 0, 0, 0, 0, PlaybackZOrderFlags);
    }

    internal bool BelongsTo(nint rootHandle) =>
        _handle != 0 && rootHandle != 0 && GetAncestor(_handle, 2) == rootHandle;

    protected override HandleRef BuildWindowCore(HandleRef hwndParent)
    {
        const int wsChild = 0x40000000;
        const int wsVisible = 0x10000000;
        const int wsClipSiblings = 0x04000000;
        const int wsClipChildren = 0x02000000;
        _handle = CreateWindowEx(
            0,
            "STATIC",
            string.Empty,
            wsChild | wsVisible | wsClipSiblings | wsClipChildren,
            0,
            0,
            1,
            1,
            hwndParent.Handle,
            0,
            0,
            0);
        if (_handle == 0) throw new InvalidOperationException("Não foi possível criar a superfície nativa do player.");
        return new HandleRef(this, _handle);
    }

    protected override void DestroyWindowCore(HandleRef hwnd)
    {
        _cursor.SetHidden(false);
        if (hwnd.Handle != 0) DestroyWindow(hwnd.Handle);
        _handle = 0;
    }

    protected override nint WndProc(nint hwnd, int message, nint wParam, nint lParam, ref bool handled)
    {
        if (message == WmSetCursor)
        {
            if (_cursor.HandleSetCursor())
            {
                handled = true;
                return 1;
            }
        }

        return base.WndProc(hwnd, message, wParam, lParam, ref handled);
    }

    [DllImport("user32.dll", CharSet = CharSet.Unicode, SetLastError = true)]
    private static extern nint CreateWindowEx(
        int extendedStyle,
        string className,
        string windowName,
        int style,
        int x,
        int y,
        int width,
        int height,
        nint parent,
        nint menu,
        nint instance,
        nint parameter);

    [DllImport("user32.dll")]
    [return: MarshalAs(UnmanagedType.Bool)]
    private static extern bool DestroyWindow(nint hwnd);

    [DllImport("user32.dll")]
    [return: MarshalAs(UnmanagedType.Bool)]
    private static extern bool IsWindow(nint hwnd);

    [DllImport("user32.dll")]
    [return: MarshalAs(UnmanagedType.Bool)]
    private static extern bool IsWindowVisible(nint hwnd);

    [DllImport("user32.dll")]
    private static extern nint GetParent(nint hwnd);

    [DllImport("user32.dll")]
    private static extern nint GetAncestor(nint hwnd, uint flags);

    [DllImport("user32.dll")]
    [return: MarshalAs(UnmanagedType.Bool)]
    private static extern bool GetWindowRect(nint hwnd, out NativeRect rectangle);

    [DllImport("user32.dll")]
    [return: MarshalAs(UnmanagedType.Bool)]
    private static extern bool GetClientRect(nint hwnd, out NativeRect rectangle);

    [DllImport("user32.dll")]
    [return: MarshalAs(UnmanagedType.Bool)]
    private static extern bool ShowWindow(nint hwnd, int command);

    [DllImport("user32.dll", SetLastError = true)]
    [return: MarshalAs(UnmanagedType.Bool)]
    private static extern bool SetWindowPos(
        nint hwnd,
        nint insertAfter,
        int x,
        int y,
        int width,
        int height,
        uint flags);

    [StructLayout(LayoutKind.Sequential)]
    private struct NativeRect
    {
        internal int Left;
        internal int Top;
        internal int Right;
        internal int Bottom;
    }
}

internal interface INativeCursorApi
{
    nint LoadArrow();
    void Set(nint cursor);
}

internal sealed class Win32NativeCursorApi : INativeCursorApi
{
    public nint LoadArrow() => LoadCursor(0, (nint)MpvVideoHostCursor.IdcArrow);
    public void Set(nint cursor) => SetCursor(cursor);

    [DllImport("user32.dll", EntryPoint = "LoadCursorW")]
    private static extern nint LoadCursor(nint instance, nint cursorName);

    [DllImport("user32.dll")]
    private static extern nint SetCursor(nint cursor);
}

internal static class MpvVideoHostCursor
{
    internal const int IdcArrow = 32512;
}

internal sealed class NativeCursorState(INativeCursorApi api)
{
    internal bool Hidden { get; private set; }

    internal void SetHidden(bool hidden)
    {
        if (Hidden == hidden) return;
        Hidden = hidden;
        Apply();
    }

    internal void ReapplyIfHidden()
    {
        if (Hidden) api.Set(0);
    }

    internal bool HandleSetCursor()
    {
        if (!Hidden) return false;
        api.Set(0);
        return true;
    }

    private void Apply() => api.Set(Hidden ? 0 : api.LoadArrow());
}

internal sealed record NativeVideoLayout(double X, double Y, double Width, double Height)
{
    internal static NativeVideoLayout? Calculate(
        Media.MpvGeometry? geometry,
        double browserWidth,
        double browserHeight)
    {
        if (geometry is null || !geometry.IsValid || !geometry.Visible || browserWidth < 1 || browserHeight < 1) return null;
        var scaleX = browserWidth / geometry.ViewportWidth;
        var scaleY = browserHeight / geometry.ViewportHeight;
        var x = Math.Clamp(geometry.X * scaleX, 0, Math.Max(0, browserWidth - 1));
        var y = Math.Clamp(geometry.Y * scaleY, 0, Math.Max(0, browserHeight - 1));
        return new NativeVideoLayout(
            x,
            y,
            Math.Clamp(geometry.Width * scaleX, 1, browserWidth - x),
            Math.Clamp(geometry.Height * scaleY, 1, browserHeight - y));
    }

    internal (int Width, int Height) PhysicalSize(double dpiScaleX, double dpiScaleY) =>
        ((int)Math.Round(Width * dpiScaleX), (int)Math.Round(Height * dpiScaleY));
}

internal sealed record NativeVideoWindowSnapshot(
    bool Exists,
    bool Visible,
    bool HasParent,
    int WindowX,
    int WindowY,
    int WindowWidth,
    int WindowHeight,
    int ClientWidth,
    int ClientHeight)
{
    internal static NativeVideoWindowSnapshot Missing { get; } = new(false, false, false, 0, 0, 0, 0, 0, 0);
}
