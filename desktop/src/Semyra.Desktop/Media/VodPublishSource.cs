using System.Diagnostics;
using System.Net;
using System.Net.Http;
using System.Net.Http.Headers;
using System.Net.Sockets;
using System.Security.Cryptography;
using System.Text.RegularExpressions;

namespace Semyra.Desktop.Media;

internal sealed record VodMediaFormat(string Container, string VideoCodec, string AudioCodec);
internal sealed record VodProxyDiagnostics(
    int RequestCount,
    int RangeRequestCount,
    int LastStatusCode,
    long BytesServed,
    double ApproximateBytesPerSecond,
    double MaximumReadGapMilliseconds,
    bool CancellationObserved);

internal sealed class VodPublishSource : IAsyncDisposable
{
    private readonly Uri _providerUri;
    private readonly HttpClient _client;
    private readonly HttpListener _listener;
    private readonly CancellationTokenSource _cancellation = new();
    private readonly Task _server;
    private readonly Stopwatch _lifetime = Stopwatch.StartNew();
    private long _requestCount;
    private long _rangeRequestCount;
    private long _lastStatusCode;
    private long _bytesServed;
    private long _maximumReadGapTicks;
    private int _cancellationObserved;

    private VodPublishSource(Uri providerUri, HttpClient client, HttpListener listener, Uri localUri)
    {
        _providerUri = providerUri;
        _client = client;
        _listener = listener;
        LocalUri = localUri;
        _server = ServeAsync(_cancellation.Token);
    }

    public Uri LocalUri { get; }

    internal VodProxyDiagnostics Diagnostics
    {
        get
        {
            var elapsedSeconds = Math.Max(_lifetime.Elapsed.TotalSeconds, 0.001);
            return new VodProxyDiagnostics(
                checked((int)Interlocked.Read(ref _requestCount)),
                checked((int)Interlocked.Read(ref _rangeRequestCount)),
                checked((int)Interlocked.Read(ref _lastStatusCode)),
                Interlocked.Read(ref _bytesServed),
                Interlocked.Read(ref _bytesServed) / elapsedSeconds,
                Interlocked.Read(ref _maximumReadGapTicks) * 1000d / Stopwatch.Frequency,
                Volatile.Read(ref _cancellationObserved) == 1);
        }
    }

    public static async Task<VodPublishSource> StartAsync(Uri providerUri, CancellationToken cancellationToken)
    {
        var client = new HttpClient(new SocketsHttpHandler
        {
            AllowAutoRedirect = true,
            AutomaticDecompression = DecompressionMethods.None,
            ConnectTimeout = TimeSpan.FromSeconds(20),
        }) { Timeout = Timeout.InfiniteTimeSpan };
        client.DefaultRequestHeaders.UserAgent.Add(new ProductInfoHeaderValue("SemyraDesktop", "1.0"));
        try
        {
            using var timeout = CancellationTokenSource.CreateLinkedTokenSource(cancellationToken);
            timeout.CancelAfter(TimeSpan.FromSeconds(30));
            using var request = new HttpRequestMessage(HttpMethod.Get, providerUri);
            request.Headers.Range = new RangeHeaderValue(0, 1);
            using var response = await client.SendAsync(request, HttpCompletionOption.ResponseHeadersRead, timeout.Token);
            if (!response.IsSuccessStatusCode) throw new MediaEngineException("provider_connect_failed");
            if (response.StatusCode != HttpStatusCode.PartialContent && response.Content.Headers.ContentRange is null)
                throw new MediaEngineException("vod_publish_unavailable");

            var port = FreePort();
            var token = Convert.ToHexString(RandomNumberGenerator.GetBytes(32)).ToLowerInvariant();
            var prefix = $"http://127.0.0.1:{port}/{token}/";
            var listener = new HttpListener();
            listener.Prefixes.Add(prefix);
            listener.Start();
            return new VodPublishSource(providerUri, client, listener, new Uri(prefix + "media"));
        }
        catch (OperationCanceledException) when (!cancellationToken.IsCancellationRequested)
        {
            client.Dispose();
            throw new MediaEngineException("provider_connect_failed");
        }
        catch
        {
            client.Dispose();
            throw;
        }
    }

    private async Task ServeAsync(CancellationToken cancellationToken)
    {
        while (!cancellationToken.IsCancellationRequested)
        {
            HttpListenerContext context;
            try { context = await _listener.GetContextAsync().WaitAsync(cancellationToken); }
            catch (OperationCanceledException) { return; }
            catch (HttpListenerException) when (cancellationToken.IsCancellationRequested) { return; }
            _ = ForwardAsync(context, cancellationToken);
        }
    }

    private async Task ForwardAsync(HttpListenerContext context, CancellationToken cancellationToken)
    {
        Interlocked.Increment(ref _requestCount);
        try
        {
            using var request = new HttpRequestMessage(context.Request.HttpMethod == "HEAD" ? HttpMethod.Head : HttpMethod.Get, _providerUri);
            var range = context.Request.Headers["Range"];
            if (!string.IsNullOrWhiteSpace(range) && RangeHeaderValue.TryParse(range, out var parsed))
            {
                request.Headers.Range = parsed;
                Interlocked.Increment(ref _rangeRequestCount);
            }
            using var response = await _client.SendAsync(request, HttpCompletionOption.ResponseHeadersRead, cancellationToken);
            context.Response.StatusCode = (int)response.StatusCode;
            Interlocked.Exchange(ref _lastStatusCode, (int)response.StatusCode);
            if (response.Content.Headers.ContentType is not null) context.Response.ContentType = response.Content.Headers.ContentType.ToString();
            if (response.Content.Headers.ContentLength is long length) context.Response.ContentLength64 = length;
            if (response.Content.Headers.ContentRange is not null) context.Response.Headers["Content-Range"] = response.Content.Headers.ContentRange.ToString();
            if (response.StatusCode == HttpStatusCode.PartialContent || response.Content.Headers.ContentRange is not null)
                context.Response.Headers["Accept-Ranges"] = "bytes";
            else if (response.Headers.AcceptRanges.Count > 0)
                context.Response.Headers["Accept-Ranges"] = string.Join(",", response.Headers.AcceptRanges);
            if (request.Method != HttpMethod.Head)
            {
                await using var upstream = await response.Content.ReadAsStreamAsync(cancellationToken);
                var buffer = new byte[64 * 1024];
                var previousRead = Stopwatch.GetTimestamp();
                while (true)
                {
                    var read = await upstream.ReadAsync(buffer, cancellationToken);
                    var now = Stopwatch.GetTimestamp();
                    UpdateMaximum(ref _maximumReadGapTicks, now - previousRead);
                    previousRead = now;
                    if (read == 0) break;
                    await context.Response.OutputStream.WriteAsync(buffer.AsMemory(0, read), cancellationToken);
                    Interlocked.Add(ref _bytesServed, read);
                }
            }
        }
        catch (OperationCanceledException) when (cancellationToken.IsCancellationRequested)
        {
            Volatile.Write(ref _cancellationObserved, 1);
        }
        catch when (!cancellationToken.IsCancellationRequested) { context.Response.StatusCode = 502; }
        finally { try { context.Response.Close(); } catch { } }
    }

    public async ValueTask DisposeAsync()
    {
        Volatile.Write(ref _cancellationObserved, 1);
        _cancellation.Cancel();
        _listener.Stop();
        try { await _server; } catch { }
        _listener.Close();
        _client.Dispose();
        _cancellation.Dispose();
    }

    private static int FreePort()
    {
        var listener = new TcpListener(IPAddress.Loopback, 0);
        listener.Start();
        var port = ((IPEndPoint)listener.LocalEndpoint).Port;
        listener.Stop();
        return port;
    }

    private static void UpdateMaximum(ref long target, long candidate)
    {
        var current = Interlocked.Read(ref target);
        while (candidate > current)
        {
            var observed = Interlocked.CompareExchange(ref target, candidate, current);
            if (observed == current) return;
            current = observed;
        }
    }
}

internal static class VodMediaProbe
{
    internal static async Task<VodMediaFormat> InspectAsync(GStreamerRuntime runtime, Uri localUri, CancellationToken cancellationToken)
    {
        if (!runtime.IsVodPublishAvailable || runtime.DiscovererPath is null) throw new MediaEngineException("vod_publish_unavailable");
        var start = new ProcessStartInfo(runtime.DiscovererPath)
        {
            UseShellExecute = false, RedirectStandardOutput = true, RedirectStandardError = true, CreateNoWindow = true,
        };
        start.ArgumentList.Add(localUri.AbsoluteUri);
        GStreamerRuntime.ConfigureProcessEnvironment(start, runtime.DiscovererPath);
        using var process = Process.Start(start) ?? throw new MediaEngineException("vod_publish_unavailable");
        var stdout = process.StandardOutput.ReadToEndAsync(cancellationToken);
        var stderr = process.StandardError.ReadToEndAsync(cancellationToken);
        using var timeout = CancellationTokenSource.CreateLinkedTokenSource(cancellationToken);
        timeout.CancelAfter(TimeSpan.FromSeconds(30));
        try { await process.WaitForExitAsync(timeout.Token); }
        catch (OperationCanceledException) when (!cancellationToken.IsCancellationRequested)
        {
            try { process.Kill(true); } catch { }
            throw new MediaEngineException("vod_publish_unavailable");
        }
        var output = await stdout + "\n" + await stderr;
        if (process.ExitCode != 0) throw new MediaEngineException("vod_container_unsupported");
        var container = Capture(output, @"container\s*#?\d*:\s*([^\r\n]+)");
        var video = Capture(output, @"video\s*#?\d*:\s*([^\r\n]+)");
        var audio = Capture(output, @"audio\s*#?\d*:\s*([^\r\n]+)");
        if (!container.Contains("quicktime", StringComparison.OrdinalIgnoreCase)
            && !container.Contains("mp4", StringComparison.OrdinalIgnoreCase))
            throw new MediaEngineException("vod_container_unsupported");
        if (!video.Contains("h.264", StringComparison.OrdinalIgnoreCase)
            || !audio.Contains("aac", StringComparison.OrdinalIgnoreCase))
            throw new MediaEngineException("vod_codec_unsupported");
        return new VodMediaFormat("mp4", "h264", "aac");
    }

    private static string Capture(string output, string pattern)
    {
        var match = Regex.Match(output, pattern, RegexOptions.IgnoreCase | RegexOptions.CultureInvariant);
        return match.Success ? match.Groups[1].Value.Trim() : string.Empty;
    }
}
