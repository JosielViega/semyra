using System.Security.Cryptography;
using System.Text.RegularExpressions;
using System.IO;

namespace Semyra.Desktop.Media;

public sealed record LocalPlaybackStartResult(MediaSnapshot Snapshot, string PlaybackUrl);

public sealed record LocalPlaybackResource(string ContentType, byte[] Content);

internal enum LocalPlaybackRequestKind
{
    NotPlayback,
    PlaybackManifest,
    PlaybackSegment,
    PlaybackInvalid,
}

internal sealed partial class LocalPlaybackSession : IDisposable
{
    private const string VirtualPrefix = "/__desktop/playback/";
    private readonly object _gate = new();
    private bool _active = true;

    private LocalPlaybackSession(string directoryPath, string token)
    {
        DirectoryPath = directoryPath;
        Token = token;
        PlaybackUrl = $"{VirtualPrefix}{token}/index.m3u8";
    }

    internal string DirectoryPath { get; }
    private string Token { get; }
    internal string PlaybackUrl { get; }

    internal static LocalPlaybackSession Create(string? cacheRoot = null)
    {
        var root = cacheRoot ?? Path.Combine(
            Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData),
            "Semyra", "Cache", "Playback");
        Directory.CreateDirectory(root);
        CleanupStaleDirectories(root);

        var directory = Path.Combine(root, Convert.ToHexString(RandomNumberGenerator.GetBytes(16)).ToLowerInvariant());
        Directory.CreateDirectory(directory);
        var token = Convert.ToHexString(RandomNumberGenerator.GetBytes(32)).ToLowerInvariant();
        return new LocalPlaybackSession(directory, token);
    }

    internal void PrepareAttempt()
    {
        lock (_gate)
        {
            EnsureActive();
            foreach (var path in Directory.EnumerateFiles(DirectoryPath))
            {
                try { File.Delete(path); } catch { }
            }
        }
    }

    internal bool TryRead(Uri requestUri, string method, out LocalPlaybackResource? resource)
    {
        resource = null;
        if (!TryResolve(requestUri, method, out var path, out var contentType))
        {
            return false;
        }

        try
        {
            resource = new LocalPlaybackResource(contentType, method == "HEAD" ? [] : ReadShared(path));
            if (IsActive()) return true;
            resource = null;
            return false;
        }
        catch (IOException) { return false; }
        catch (UnauthorizedAccessException) { return false; }
    }

    internal async Task<LocalPlaybackResource?> ReadAsync(Uri requestUri, string method, CancellationToken cancellationToken = default)
    {
        if (!TryResolve(requestUri, method, out var path, out var contentType))
        {
            return null;
        }
        if (method == "HEAD")
        {
            return IsActive() ? new LocalPlaybackResource(contentType, []) : null;
        }
        try
        {
            await using var stream = new FileStream(
                path, FileMode.Open, FileAccess.Read, FileShare.ReadWrite | FileShare.Delete,
                64 * 1024, FileOptions.Asynchronous | FileOptions.SequentialScan);
            if (stream.Length > 64 * 1024 * 1024) return null;
            var expectedLength = stream.Length;
            using var content = new MemoryStream((int)expectedLength);
            await stream.CopyToAsync(content, cancellationToken);
            if (stream.Length != expectedLength) return null;
            return IsActive() ? new LocalPlaybackResource(contentType, content.ToArray()) : null;
        }
        catch (IOException) { return null; }
        catch (UnauthorizedAccessException) { return null; }
    }

    internal LocalPlaybackRequestKind ClassifyRequest(Uri requestUri, string method)
    {
        if (!IsReservedNamespace(requestUri))
        {
            return LocalPlaybackRequestKind.NotPlayback;
        }
        if (method is not ("GET" or "HEAD") || requestUri.Query.Length != 0 || requestUri.Fragment.Length != 0)
        {
            return LocalPlaybackRequestKind.PlaybackInvalid;
        }

        var escapedPath = requestUri.GetComponents(UriComponents.Path, UriFormat.UriEscaped);
        var prefix = $"__desktop/playback/{Token}/";
        if (!escapedPath.StartsWith(prefix, StringComparison.Ordinal))
        {
            return LocalPlaybackRequestKind.PlaybackInvalid;
        }

        var fileName = escapedPath[prefix.Length..];
        if (string.Equals(fileName, "index.m3u8", StringComparison.Ordinal))
        {
            return LocalPlaybackRequestKind.PlaybackManifest;
        }
        return SegmentFileName().IsMatch(fileName)
            ? LocalPlaybackRequestKind.PlaybackSegment
            : LocalPlaybackRequestKind.PlaybackInvalid;
    }

    internal static bool IsReservedNamespace(Uri requestUri)
    {
        var escapedPath = requestUri.GetComponents(UriComponents.Path, UriFormat.UriEscaped);
        return escapedPath.StartsWith("__desktop/playback/", StringComparison.Ordinal);
    }

    private bool TryResolve(Uri requestUri, string method, out string path, out string contentType)
    {
        path = string.Empty;
        contentType = string.Empty;
        if (method is not ("GET" or "HEAD") || requestUri.Query.Length != 0 || requestUri.Fragment.Length != 0)
        {
            return false;
        }

        var escapedPath = requestUri.GetComponents(UriComponents.Path, UriFormat.UriEscaped);
        var prefix = $"__desktop/playback/{Token}/";
        if (!escapedPath.StartsWith(prefix, StringComparison.Ordinal)
            || escapedPath.Length <= prefix.Length)
        {
            return false;
        }
        var fileName = escapedPath[prefix.Length..];
        if (!AllowedFileName().IsMatch(fileName))
        {
            return false;
        }

        lock (_gate)
        {
            if (!_active)
            {
                return false;
            }
            path = Path.Combine(DirectoryPath, fileName);
            if (!File.Exists(path))
            {
                return false;
            }
            contentType = fileName.EndsWith(".m3u8", StringComparison.Ordinal)
                ? "application/vnd.apple.mpegurl" : "video/mp2t";
            return true;
        }
    }

    private static byte[] ReadShared(string path)
    {
        using var stream = new FileStream(path, FileMode.Open, FileAccess.Read, FileShare.ReadWrite | FileShare.Delete);
        if (stream.Length > 64 * 1024 * 1024) throw new IOException("Playback resource is too large.");
        var expectedLength = stream.Length;
        using var content = new MemoryStream((int)expectedLength);
        stream.CopyTo(content);
        if (stream.Length != expectedLength) throw new IOException("Playback resource changed while reading.");
        return content.ToArray();
    }

    public void Dispose()
    {
        lock (_gate)
        {
            if (!_active)
            {
                return;
            }
            _active = false;
        }
        try
        {
            Directory.Delete(DirectoryPath, true);
        }
        catch
        {
        }
    }

    private void EnsureActive()
    {
        if (!_active) throw new ObjectDisposedException(nameof(LocalPlaybackSession));
    }

    private bool IsActive()
    {
        lock (_gate) return _active;
    }

    private static void CleanupStaleDirectories(string root)
    {
        foreach (var directory in Directory.EnumerateDirectories(root))
        {
            try
            {
                if (Directory.GetLastWriteTimeUtc(directory) < DateTime.UtcNow.AddDays(-1))
                {
                    Directory.Delete(directory, true);
                }
            }
            catch { }
        }
    }

    [GeneratedRegex("^(?:index\\.m3u8|segment[0-9]{5}\\.ts)$", RegexOptions.CultureInvariant)]
    private static partial Regex AllowedFileName();

    [GeneratedRegex("^segment[0-9]{5}\\.ts$", RegexOptions.CultureInvariant)]
    private static partial Regex SegmentFileName();
}
