using Semyra.Desktop.Media;

namespace Semyra.Desktop.Tests;

public sealed class LocalPlaybackSessionTests : IDisposable
{
    private readonly string _root = Path.Combine(Path.GetTempPath(), "semyra-playback-tests", Guid.NewGuid().ToString("N"));

    [Fact]
    public void ServesOnlyCurrentTokenGetOrHeadAndAllowlistedNames()
    {
        using var session = LocalPlaybackSession.Create(_root);
        File.WriteAllText(Path.Combine(session.DirectoryPath, "index.m3u8"), "#EXTM3U\nsegment00001.ts\n");
        File.WriteAllBytes(Path.Combine(session.DirectoryPath, "segment00001.ts"), [0x47, 0x00]);
        var origin = new Uri("https://semyra.example/");
        var playlist = new Uri(origin, session.PlaybackUrl);

        Assert.True(session.TryRead(playlist, "GET", out var get));
        Assert.NotEmpty(get!.Content);
        Assert.True(session.TryRead(playlist, "HEAD", out var head));
        Assert.Empty(head!.Content);
        Assert.False(session.TryRead(playlist, "POST", out _));
        Assert.False(session.TryRead(new Uri(playlist + "?x=1"), "GET", out _));
        Assert.False(session.TryRead(new Uri(origin, session.PlaybackUrl.Replace("index.m3u8", "../secret")), "GET", out _));
        Assert.False(session.TryRead(new Uri(origin, session.PlaybackUrl.Replace("index.m3u8", "%2e%2e%2fsecret")), "GET", out _));
        Assert.False(session.TryRead(new Uri(origin, session.PlaybackUrl.Replace("index.m3u8", "other.txt")), "GET", out _));
        Assert.False(session.TryRead(new Uri(origin, "/__desktop/playback/" + new string('b', 64) + "/index.m3u8"), "GET", out _));
    }

    [Fact]
    public void DisposeInvalidatesTokenAndDeletesTemporaryDirectory()
    {
        var session = LocalPlaybackSession.Create(_root);
        File.WriteAllText(Path.Combine(session.DirectoryPath, "index.m3u8"), "#EXTM3U\n");
        var directory = session.DirectoryPath;
        var uri = new Uri(new Uri("https://semyra.example/"), session.PlaybackUrl);

        session.Dispose();

        Assert.False(session.TryRead(uri, "GET", out _));
        Assert.False(Directory.Exists(directory));
    }

    [Fact]
    public void SessionsUseRandomAnonymousPathsAndCleanStaleCrashData()
    {
        var stale = Path.Combine(_root, "stale-session");
        Directory.CreateDirectory(stale);
        Directory.SetLastWriteTimeUtc(stale, DateTime.UtcNow.AddDays(-2));

        using var first = LocalPlaybackSession.Create(_root);
        using var second = LocalPlaybackSession.Create(_root);

        Assert.NotEqual(first.DirectoryPath, second.DirectoryPath);
        Assert.NotEqual(first.PlaybackUrl, second.PlaybackUrl);
        Assert.DoesNotContain("profile", first.DirectoryPath, StringComparison.OrdinalIgnoreCase);
        Assert.DoesNotContain("channel", first.DirectoryPath, StringComparison.OrdinalIgnoreCase);
        Assert.False(Directory.Exists(stale));
    }

    public void Dispose()
    {
        if (Directory.Exists(_root)) Directory.Delete(_root, true);
    }
}
