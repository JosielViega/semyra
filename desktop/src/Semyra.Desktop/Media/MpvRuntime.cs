using System.Diagnostics;
using System.IO;

namespace Semyra.Desktop.Media;

internal enum LocalPlayerMode
{
    Auto,
    Legacy,
    Mpv,
}

internal enum LocalPlaybackEngine
{
    Unavailable,
    Legacy,
    Mpv,
}

internal sealed record LocalPlayerOptions(LocalPlayerMode Mode)
{
    internal static LocalPlayerOptions FromEnvironment(Func<string, string?>? environment = null)
    {
        environment ??= Environment.GetEnvironmentVariable;
        return environment("SEMYRA_LOCAL_PLAYER")?.Trim().ToLowerInvariant() switch
        {
            "legacy" => new LocalPlayerOptions(LocalPlayerMode.Legacy),
            "mpv" => new LocalPlayerOptions(LocalPlayerMode.Mpv),
            _ => new LocalPlayerOptions(LocalPlayerMode.Auto),
        };
    }
}

internal sealed record MpvRuntime(bool Available, string? ExecutablePath, string? Version, string? Origin, string? ErrorCode)
{
    internal static MpvRuntime Unavailable(string error = "mpv_runtime_unavailable") =>
        new(false, null, null, null, error);

    internal static MpvRuntime DiscoverDefault(
        string? applicationDirectory = null,
        Func<string, string?>? environment = null,
        Func<string, string?>? versionProbe = null)
    {
        applicationDirectory ??= AppContext.BaseDirectory;
        environment ??= Environment.GetEnvironmentVariable;
        versionProbe ??= ProbeVersion;

        var bundled = Path.Combine(applicationDirectory, "runtime", "mpv", "mpv.exe");
        if (File.Exists(bundled))
        {
            return Found(bundled, "app-runtime", versionProbe);
        }

        var configured = environment("SEMYRA_MPV_HOME");
        if (!string.IsNullOrWhiteSpace(configured))
        {
            var candidate = configured.EndsWith(".exe", StringComparison.OrdinalIgnoreCase)
                ? configured
                : Path.Combine(configured, "mpv.exe");
            if (File.Exists(candidate))
            {
                return Found(candidate, "SEMYRA_MPV_HOME", versionProbe);
            }
        }

#if DEBUG
        foreach (var directory in (environment("PATH") ?? string.Empty)
                     .Split(Path.PathSeparator, StringSplitOptions.RemoveEmptyEntries | StringSplitOptions.TrimEntries))
        {
            var candidate = Path.Combine(directory, "mpv.exe");
            if (File.Exists(candidate))
            {
                return Found(candidate, "PATH", versionProbe);
            }
        }
#endif

        return Unavailable();
    }

    private static MpvRuntime Found(string path, string origin, Func<string, string?> versionProbe) =>
        new(true, Path.GetFullPath(path), versionProbe(path), origin, null);

    private static string? ProbeVersion(string executable)
    {
        try
        {
            using var process = Process.Start(new ProcessStartInfo
            {
                FileName = executable,
                UseShellExecute = false,
                RedirectStandardOutput = true,
                RedirectStandardError = true,
                CreateNoWindow = true,
                ArgumentList = { "--version" },
            });
            if (process is null || !process.WaitForExit(3000))
            {
                try { process?.Kill(true); } catch { }
                return null;
            }
            var first = process.StandardOutput.ReadLine();
            return string.IsNullOrWhiteSpace(first) ? null : first.Trim()[..Math.Min(first.Trim().Length, 120)];
        }
        catch
        {
            return null;
        }
    }
}
