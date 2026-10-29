using System.Runtime.InteropServices;
using System.Security.Cryptography;
using System.Text;

namespace Semyra.Desktop.Screen;

public sealed record ScreenCaptureTargetSummary(string Id, string Type, string Name, int Width, int Height, bool Available);

internal sealed record ScreenCaptureTarget(string Id, string Type, string Name, int Width, int Height, nint Handle);

internal sealed class ScreenCaptureTargetRegistry
{
    private readonly object _sync = new();
    private readonly Dictionary<string, ScreenCaptureTarget> _targets = new(StringComparer.Ordinal);
    private string? _contextId;
    private readonly Func<IReadOnlyList<ScreenCaptureTarget>> _discover;
    private readonly Func<ScreenCaptureTarget, bool> _isAvailable;

    internal ScreenCaptureTargetRegistry(Func<IReadOnlyList<ScreenCaptureTarget>>? discover = null,
        Func<ScreenCaptureTarget, bool>? isAvailable = null)
    {
        _discover = discover ?? Discover;
        _isAvailable = isAvailable ?? IsTargetAvailable;
    }

    internal IReadOnlyList<ScreenCaptureTargetSummary> Refresh(string contextId)
    {
        if (!ValidContext(contextId)) throw new ArgumentException("Invalid account context.", nameof(contextId));
        var discovered = _discover();
        lock (_sync)
        {
            _contextId = contextId;
            _targets.Clear();
            foreach (var target in discovered) _targets[target.Id] = target;
            return discovered.Select(ToSummary).ToArray();
        }
    }

    internal ScreenCaptureTarget Resolve(string contextId, string targetId)
    {
        lock (_sync)
        {
            if (_contextId is null || !FixedEquals(_contextId, contextId)
                || !_targets.TryGetValue(targetId, out var target))
                throw new ScreenPublisherException("screen_target_changed");
            if (!_isAvailable(target))
            {
                _targets.Remove(targetId);
                throw new ScreenPublisherException("screen_target_changed");
            }
            return target;
        }
    }

    internal void Invalidate()
    {
        lock (_sync) { _contextId = null; _targets.Clear(); }
    }

    private static IReadOnlyList<ScreenCaptureTarget> Discover()
    {
        var targets = new List<ScreenCaptureTarget>();
        var monitorNumber = 0;
        EnumDisplayMonitors(0, 0, (nint monitor, nint _, ref NativeRect bounds, nint _) =>
        {
            monitorNumber++;
            var info = new MonitorInfo { Size = Marshal.SizeOf<MonitorInfo>() };
            _ = GetMonitorInfo(monitor, ref info);
            var name = (info.Flags & 1) != 0 ? "Monitor principal" : $"Monitor {monitorNumber}";
            targets.Add(Create("monitor", name, bounds.Right - bounds.Left, bounds.Bottom - bounds.Top, monitor));
            return true;
        }, 0);

        EnumWindows((window, _) =>
        {
            if (!IsWindowVisible(window) || IsIconic(window)) return true;
            var length = GetWindowTextLength(window);
            if (length is < 1 or > 500 || !GetWindowRect(window, out var bounds)) return true;
            var width = bounds.Right - bounds.Left;
            var height = bounds.Bottom - bounds.Top;
            if (width < 120 || height < 80) return true;
            var title = new StringBuilder(length + 1);
            _ = GetWindowText(window, title, title.Capacity);
            var name = title.ToString().Trim();
            if (name.Length == 0) return true;
            targets.Add(Create("window", name[..Math.Min(name.Length, 160)], width, height, window));
            return true;
        }, 0);
        return targets;
    }

    internal static ScreenCaptureTarget Create(string type, string name, int width, int height, nint handle) =>
        new(Convert.ToHexString(RandomNumberGenerator.GetBytes(16)).ToLowerInvariant(), type, name, width, height, handle);

    private static ScreenCaptureTargetSummary ToSummary(ScreenCaptureTarget value) =>
        new(value.Id, value.Type, value.Name, value.Width, value.Height, true);

    private static bool ValidContext(string value) => value.Length == 32 && value.All(Uri.IsHexDigit);
    private static bool FixedEquals(string left, string right) => left.Length == right.Length
        && CryptographicOperations.FixedTimeEquals(Encoding.ASCII.GetBytes(left), Encoding.ASCII.GetBytes(right));
    private static bool IsTargetAvailable(ScreenCaptureTarget target)
    {
        if (target.Type == "window") return IsWindow(target.Handle) && IsWindowVisible(target.Handle) && !IsIconic(target.Handle);
        var info = new MonitorInfo { Size = Marshal.SizeOf<MonitorInfo>() };
        return GetMonitorInfo(target.Handle, ref info);
    }

    private delegate bool MonitorCallback(nint monitor, nint hdc, ref NativeRect bounds, nint data);
    private delegate bool WindowCallback(nint window, nint data);
    [DllImport("user32.dll")] private static extern bool EnumDisplayMonitors(nint hdc, nint clip, MonitorCallback callback, nint data);
    [DllImport("user32.dll")] private static extern bool EnumWindows(WindowCallback callback, nint data);
    [DllImport("user32.dll")] private static extern bool IsWindowVisible(nint window);
    [DllImport("user32.dll")] private static extern bool IsWindow(nint window);
    [DllImport("user32.dll")] private static extern bool IsIconic(nint window);
    [DllImport("user32.dll", CharSet = CharSet.Unicode)] private static extern int GetWindowText(nint window, StringBuilder value, int count);
    [DllImport("user32.dll")] private static extern int GetWindowTextLength(nint window);
    [DllImport("user32.dll")] private static extern bool GetWindowRect(nint window, out NativeRect bounds);
    [DllImport("user32.dll", CharSet = CharSet.Auto)] private static extern bool GetMonitorInfo(nint monitor, ref MonitorInfo info);
    [StructLayout(LayoutKind.Sequential)] private struct NativeRect { public int Left, Top, Right, Bottom; }
    [StructLayout(LayoutKind.Sequential, CharSet = CharSet.Auto)] private struct MonitorInfo
    { public int Size; public NativeRect Monitor; public NativeRect Work; public uint Flags; }
}
