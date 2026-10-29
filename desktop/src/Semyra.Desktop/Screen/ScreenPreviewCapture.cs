using System.IO;
using System.Runtime.InteropServices;
using System.Windows;
using System.Windows.Interop;
using System.Windows.Media;
using System.Windows.Media.Imaging;

namespace Semyra.Desktop.Screen;

public sealed record ScreenPreviewResult(string DataUrl, int Width, int Height);

internal interface IScreenPreviewCapture
{
    Task<ScreenPreviewResult> CaptureAsync(ScreenCaptureTarget target, CancellationToken cancellationToken);
}

internal sealed class ScreenPreviewCapture : IScreenPreviewCapture
{
    internal const int MaximumWidth = 400;
    internal const int MaximumBytes = 256 * 1024;
    internal static readonly TimeSpan CaptureTimeout = TimeSpan.FromSeconds(3);
    private readonly SemaphoreSlim _gate = new(1, 1);
    private readonly Func<ScreenCaptureTarget, ScreenPreviewResult> _capture;
    private readonly TimeSpan _timeout;

    internal ScreenPreviewCapture(
        Func<ScreenCaptureTarget, ScreenPreviewResult>? capture = null,
        TimeSpan? timeout = null)
    {
        _capture = capture ?? Capture;
        _timeout = timeout ?? CaptureTimeout;
        if (_timeout <= TimeSpan.Zero) throw new ArgumentOutOfRangeException(nameof(timeout));
    }

    public async Task<ScreenPreviewResult> CaptureAsync(
        ScreenCaptureTarget target,
        CancellationToken cancellationToken)
    {
        await _gate.WaitAsync(cancellationToken);
        var capture = Task.Run(() => _capture(target), CancellationToken.None);
        try
        {
            return await capture.WaitAsync(_timeout, cancellationToken);
        }
        catch (TimeoutException exception)
        {
            throw new ScreenPublisherException("screen_preview_timeout", exception);
        }
        catch (OperationCanceledException)
        {
            throw;
        }
        catch (ScreenPublisherException)
        {
            throw;
        }
        catch (Exception exception)
        {
            throw new ScreenPublisherException("screen_preview_unavailable", exception);
        }
        finally
        {
            if (capture.IsCompleted)
            {
                _gate.Release();
            }
            else
            {
                _ = capture.ContinueWith(
                    _ => _gate.Release(),
                    CancellationToken.None,
                    TaskContinuationOptions.ExecuteSynchronously,
                    TaskScheduler.Default);
            }
        }
    }

    internal static (int Width, int Height) PreviewDimensions(int width, int height)
    {
        if (width <= 0 || height <= 0) throw new ScreenPublisherException("screen_preview_unavailable");
        var scale = Math.Min(1d, (double)MaximumWidth / width);
        return (Math.Max(1, (int)Math.Round(width * scale)), Math.Max(1, (int)Math.Round(height * scale)));
    }

    internal static ScreenPreviewResult Encode(BitmapSource source)
    {
        var (width, height) = PreviewDimensions(source.PixelWidth, source.PixelHeight);
        BitmapSource thumbnail = source;
        if (width != source.PixelWidth || height != source.PixelHeight)
        {
            thumbnail = new TransformedBitmap(source, new ScaleTransform(
                (double)width / source.PixelWidth,
                (double)height / source.PixelHeight));
            thumbnail.Freeze();
        }

        var bytes = EncodeJpeg(thumbnail, 72);
        if (bytes.Length > MaximumBytes) bytes = EncodeJpeg(thumbnail, 50);
        if (bytes.Length > MaximumBytes) throw new ScreenPublisherException("screen_preview_too_large");
        return new ScreenPreviewResult($"data:image/jpeg;base64,{Convert.ToBase64String(bytes)}", width, height);
    }

    private static ScreenPreviewResult Capture(ScreenCaptureTarget target)
    {
        if (!TryBounds(target, out var bounds)) throw new ScreenPublisherException("screen_target_changed");
        var width = bounds.Right - bounds.Left;
        var height = bounds.Bottom - bounds.Top;
        if (width <= 0 || height <= 0) throw new ScreenPublisherException("screen_preview_unavailable");

        var sourceDc = GetDC(0);
        if (sourceDc == 0) throw new ScreenPublisherException("screen_preview_unavailable");
        var memoryDc = CreateCompatibleDC(sourceDc);
        var bitmap = memoryDc == 0 ? 0 : CreateCompatibleBitmap(sourceDc, width, height);
        var previous = bitmap == 0 ? 0 : SelectObject(memoryDc, bitmap);
        try
        {
            if (memoryDc == 0 || bitmap == 0 || previous == 0 || previous == new nint(-1))
                throw new ScreenPublisherException("screen_preview_unavailable");
            var captured = target.Type == "window"
                ? PrintWindow(target.Handle, memoryDc, PrintWindowFullContent)
                : BitBlt(memoryDc, 0, 0, width, height, sourceDc, bounds.Left, bounds.Top, SourceCopy | CaptureBlt);
            if (!captured) throw new ScreenPublisherException("screen_preview_unavailable");

            var source = Imaging.CreateBitmapSourceFromHBitmap(
                bitmap,
                0,
                Int32Rect.Empty,
                BitmapSizeOptions.FromEmptyOptions());
            source.Freeze();
            return Encode(source);
        }
        finally
        {
            if (previous != 0 && memoryDc != 0) _ = SelectObject(memoryDc, previous);
            if (bitmap != 0) _ = DeleteObject(bitmap);
            if (memoryDc != 0) _ = DeleteDC(memoryDc);
            _ = ReleaseDC(0, sourceDc);
        }
    }

    private static byte[] EncodeJpeg(BitmapSource source, int quality)
    {
        var encoder = new JpegBitmapEncoder { QualityLevel = quality };
        encoder.Frames.Add(BitmapFrame.Create(source));
        using var stream = new MemoryStream();
        encoder.Save(stream);
        return stream.ToArray();
    }

    private static bool TryBounds(ScreenCaptureTarget target, out NativeRect bounds)
    {
        if (target.Type == "window") return GetWindowRect(target.Handle, out bounds) && !IsIconic(target.Handle);
        var info = new MonitorInfo { Size = Marshal.SizeOf<MonitorInfo>() };
        if (!GetMonitorInfo(target.Handle, ref info)) { bounds = default; return false; }
        bounds = info.Monitor;
        return true;
    }

    private const uint SourceCopy = 0x00CC0020;
    private const uint CaptureBlt = 0x40000000;
    private const uint PrintWindowFullContent = 0x00000002;
    [DllImport("user32.dll")] private static extern nint GetDC(nint window);
    [DllImport("user32.dll")] private static extern int ReleaseDC(nint window, nint dc);
    [DllImport("gdi32.dll")] private static extern nint CreateCompatibleDC(nint dc);
    [DllImport("gdi32.dll")] private static extern bool DeleteDC(nint dc);
    [DllImport("gdi32.dll")] private static extern nint CreateCompatibleBitmap(nint dc, int width, int height);
    [DllImport("gdi32.dll")] private static extern nint SelectObject(nint dc, nint value);
    [DllImport("gdi32.dll")] private static extern bool DeleteObject(nint value);
    [DllImport("gdi32.dll")] private static extern bool BitBlt(nint destination, int x, int y, int width, int height,
        nint source, int sourceX, int sourceY, uint operation);
    [DllImport("user32.dll")] private static extern bool PrintWindow(nint window, nint dc, uint flags);
    [DllImport("user32.dll")] private static extern bool GetWindowRect(nint window, out NativeRect bounds);
    [DllImport("user32.dll")] private static extern bool IsIconic(nint window);
    [DllImport("user32.dll", CharSet = CharSet.Auto)] private static extern bool GetMonitorInfo(nint monitor, ref MonitorInfo info);
    [StructLayout(LayoutKind.Sequential)] private struct NativeRect { public int Left, Top, Right, Bottom; }
    [StructLayout(LayoutKind.Sequential, CharSet = CharSet.Auto)] private struct MonitorInfo
    { public int Size; public NativeRect Monitor; public NativeRect Work; public uint Flags; }
}
