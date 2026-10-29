using System.Diagnostics;
using System.IO;

namespace Semyra.Desktop.Media;

public sealed record GStreamerRuntimeSnapshot(
    bool Available,
    bool WhipAvailable,
    string? Version,
    string? ErrorCode,
    bool ScreenCaptureAvailable = false,
    bool ScreenAudioAvailable = false,
    IReadOnlyList<string>? ScreenEncoderCandidates = null);

internal interface IGStreamerCommandRunner
{
    (int ExitCode, string Output) Run(string executable, IReadOnlyList<string> arguments);
}

internal sealed class GStreamerCommandRunner : IGStreamerCommandRunner
{
    public (int ExitCode, string Output) Run(string executable, IReadOnlyList<string> arguments)
    {
        var startInfo = new ProcessStartInfo(executable)
        {
            UseShellExecute = false,
            RedirectStandardOutput = true,
            RedirectStandardError = true,
            CreateNoWindow = true,
        };
        foreach (var argument in arguments)
        {
            startInfo.ArgumentList.Add(argument);
        }
        GStreamerRuntime.ConfigureProcessEnvironment(startInfo, executable);
        using var process = Process.Start(startInfo) ?? throw new InvalidOperationException("GStreamer did not start.");
        var stdout = process.StandardOutput.ReadToEnd();
        var stderr = process.StandardError.ReadToEnd();
        if (!process.WaitForExit(10_000))
        {
            process.Kill(true);
            return (-1, string.Empty);
        }
        return (process.ExitCode, stdout.Length > 0 ? stdout : stderr);
    }
}

public sealed class GStreamerRuntime
{
    internal static readonly string[] RequiredElements =
    [
        "fdsrc", "queue", "tsdemux", "h264parse", "avdec_h264", "videoconvert", "x264enc", "rtph264pay", "aacparse",
        "avdec_aac", "audioconvert", "audioresample", "opusenc", "rtpopuspay",
    ];
    internal static readonly string[] RequiredVodElements =
    [
        "souphttpsrc", "qtdemux", "h264parse", "avdec_h264", "videoconvert", "x264enc", "clocksync", "identity", "rtph264pay", "aacparse",
        "avdec_aac", "audioconvert", "audioresample", "opusenc", "rtpopuspay",
        "intervideosink", "intervideosrc", "interaudiosink", "interaudiosrc",
    ];

    private GStreamerRuntime(string? launchPath, string? inspectPath, string? discovererPath, bool vodPublishAvailable, GStreamerRuntimeSnapshot snapshot)
    {
        LaunchPath = launchPath;
        InspectPath = inspectPath;
        DiscovererPath = discovererPath;
        IsVodPublishAvailable = vodPublishAvailable;
        Snapshot = snapshot;
    }

    public string? LaunchPath { get; }
    public string? InspectPath { get; }
    public string? DiscovererPath { get; }
    public GStreamerRuntimeSnapshot Snapshot { get; }
    public bool IsAvailable => Snapshot.Available;
    public bool IsWhipAvailable => Snapshot.Available && Snapshot.WhipAvailable;
    public bool IsVodPublishAvailable { get; }
    public bool IsScreenVideoAvailable => Snapshot.ScreenCaptureAvailable && IsWhipAvailable && ScreenEncoderCandidates.Count > 0;
    public bool IsScreenAudioAvailable => Snapshot.ScreenAudioAvailable && IsScreenVideoAvailable;
    public IReadOnlyList<string> ScreenEncoderCandidates => Snapshot.ScreenEncoderCandidates ?? Array.Empty<string>();

    public static GStreamerRuntime DiscoverDefault()
    {
        return Discover(AppContext.BaseDirectory, new GStreamerCommandRunner());
    }

    internal static GStreamerRuntime Discover(string appDirectory, IGStreamerCommandRunner runner)
    {
        foreach (var directory in CandidateDirectories(appDirectory))
        {
            var launch = Path.Combine(directory, "gst-launch-1.0.exe");
            var inspect = Path.Combine(directory, "gst-inspect-1.0.exe");
            var discoverer = Path.Combine(directory, "gst-discoverer-1.0.exe");
            if (!File.Exists(launch) || !File.Exists(inspect))
            {
                continue;
            }
            try
            {
                var versionResult = runner.Run(launch, ["--version"]);
                if (versionResult.ExitCode != 0)
                {
                    continue;
                }
                foreach (var element in RequiredElements)
                {
                    if (runner.Run(inspect, [element]).ExitCode != 0)
                    {
                        return new GStreamerRuntime(launch, inspect, null, false,
                            new GStreamerRuntimeSnapshot(false, false, SafeVersion(versionResult.Output), "media_runtime_unavailable"));
                    }
                }
                var whipAvailable = runner.Run(inspect, ["whipsink"]).ExitCode == 0;
                var screenCapture = runner.Run(inspect, ["d3d11screencapturesrc"]).ExitCode == 0
                    && runner.Run(inspect, ["d3d11download"]).ExitCode == 0
                    && runner.Run(inspect, ["videoscale"]).ExitCode == 0
                    && runner.Run(inspect, ["videorate"]).ExitCode == 0;
                var screenAudio = runner.Run(inspect, ["wasapi2src"]).ExitCode == 0;
                var encoders = new[] { "nvh264enc", "mfh264enc", "x264enc" }
                    .Where(element => runner.Run(inspect, [element]).ExitCode == 0)
                    .ToArray();
                var vodAvailable = whipAvailable && File.Exists(discoverer)
                    && RequiredVodElements.All(element => runner.Run(inspect, [element]).ExitCode == 0);
                return new GStreamerRuntime(launch, inspect, File.Exists(discoverer) ? discoverer : null, vodAvailable,
                    new GStreamerRuntimeSnapshot(true, whipAvailable, SafeVersion(versionResult.Output), null,
                        screenCapture, screenAudio, encoders));
            }
            catch
            {
                return new GStreamerRuntime(null, null, null, false,
                    new GStreamerRuntimeSnapshot(false, false, null, "media_runtime_unavailable"));
            }
        }
        return new GStreamerRuntime(null, null, null, false,
            new GStreamerRuntimeSnapshot(false, false, null, "media_runtime_unavailable"));
    }

    internal static GStreamerRuntime AvailableForTests(string launchPath = "gst-launch-1.0.exe", bool whipAvailable = true)
    {
        return new GStreamerRuntime(launchPath, "gst-inspect-1.0.exe", "gst-discoverer-1.0.exe", whipAvailable,
            new GStreamerRuntimeSnapshot(true, whipAvailable, "test", null, true, true, ["x264enc"]));
    }

    internal static GStreamerRuntime UnavailableForTests() =>
        new(null, null, null, false, new GStreamerRuntimeSnapshot(false, false, null, "media_runtime_unavailable"));

    internal static void ConfigureProcessEnvironment(ProcessStartInfo startInfo, string executable)
    {
        var binaryDirectory = Path.GetDirectoryName(Path.GetFullPath(executable));
        if (string.IsNullOrWhiteSpace(binaryDirectory))
        {
            return;
        }
        startInfo.Environment["PATH"] = binaryDirectory + Path.PathSeparator
            + (startInfo.Environment.TryGetValue("PATH", out var existingPath) ? existingPath : string.Empty);
        var runtimeRoot = Directory.GetParent(binaryDirectory)?.FullName;
        if (runtimeRoot is not null)
        {
            var pluginDirectory = Path.Combine(runtimeRoot, "lib", "gstreamer-1.0");
            if (Directory.Exists(pluginDirectory))
            {
                startInfo.Environment["GST_PLUGIN_SYSTEM_PATH_1_0"] = pluginDirectory;
            }
        }
    }

    private static IEnumerable<string> CandidateDirectories(string appDirectory)
    {
        yield return Path.Combine(appDirectory, "runtime", "gstreamer", "bin");
#if DEBUG
        var developmentHome = Environment.GetEnvironmentVariable("SEMYRA_GSTREAMER_HOME");
        if (!string.IsNullOrWhiteSpace(developmentHome))
        {
            yield return Path.Combine(developmentHome, "bin");
            yield return developmentHome;
        }
        yield return Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.ProgramFiles), "gstreamer", "1.0", "msvc_x86_64", "bin");
        foreach (var item in (Environment.GetEnvironmentVariable("PATH") ?? string.Empty).Split(Path.PathSeparator, StringSplitOptions.RemoveEmptyEntries))
        {
            yield return item.Trim();
        }
#endif
    }

    private static string? SafeVersion(string output)
    {
        var line = output.Split(['\r', '\n'], StringSplitOptions.RemoveEmptyEntries)
            .FirstOrDefault(value => value.Contains("GStreamer", StringComparison.OrdinalIgnoreCase));
        return line is null ? null : line.Trim()[..Math.Min(line.Trim().Length, 100)];
    }
}
