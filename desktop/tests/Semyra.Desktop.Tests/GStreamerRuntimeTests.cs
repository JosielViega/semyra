using Semyra.Desktop.Media;

namespace Semyra.Desktop.Tests;

public sealed class GStreamerRuntimeTests : IDisposable
{
    private readonly string _directory = Path.Combine(Path.GetTempPath(), "semyra-gst-tests", Guid.NewGuid().ToString("N"));

    [Fact]
    public void ValidRuntimeRequiresEveryElement()
    {
        var binaryDirectory = CreateExecutables();
        var runner = new RecordingRunner();

        var runtime = GStreamerRuntime.Discover(_directory, runner);

        Assert.True(runtime.IsAvailable);
        Assert.True(runtime.IsWhipAvailable);
        Assert.Equal("GStreamer 1.26.11", runtime.Snapshot.Version);
        Assert.Equal([.. GStreamerRuntime.RequiredElements, "whipsink"], runner.Inspected);
        Assert.StartsWith(binaryDirectory, runtime.LaunchPath, StringComparison.OrdinalIgnoreCase);
    }

    [Fact]
    public void MissingWhipSinkPreservesLocalPlaybackCapability()
    {
        CreateExecutables();
        var runtime = GStreamerRuntime.Discover(_directory, new RecordingRunner("whipsink"));

        Assert.True(runtime.IsAvailable);
        Assert.False(runtime.IsWhipAvailable);
        Assert.Null(runtime.Snapshot.ErrorCode);
    }

    [Fact]
    public void MissingRequiredElementDisablesRuntime()
    {
        CreateExecutables();
        var runtime = GStreamerRuntime.Discover(_directory, new RecordingRunner("avdec_aac"));

        Assert.False(runtime.IsAvailable);
        Assert.Equal("media_runtime_unavailable", runtime.Snapshot.ErrorCode);
    }

    public void Dispose()
    {
        if (Directory.Exists(_directory)) Directory.Delete(_directory, true);
    }

    private string CreateExecutables()
    {
        var binaryDirectory = Path.Combine(_directory, "runtime", "gstreamer", "bin");
        Directory.CreateDirectory(binaryDirectory);
        File.WriteAllBytes(Path.Combine(binaryDirectory, "gst-launch-1.0.exe"), []);
        File.WriteAllBytes(Path.Combine(binaryDirectory, "gst-inspect-1.0.exe"), []);
        return binaryDirectory;
    }

    private sealed class RecordingRunner(string? missing = null) : IGStreamerCommandRunner
    {
        public List<string> Inspected { get; } = [];
        public (int ExitCode, string Output) Run(string executable, IReadOnlyList<string> arguments)
        {
            if (arguments.SequenceEqual(["--version"]))
            {
                return (0, "gst-launch-1.0 version 1.26.11\nGStreamer 1.26.11");
            }
            var element = arguments.Single();
            Inspected.Add(element);
            return string.Equals(element, missing, StringComparison.Ordinal) ? (1, string.Empty) : (0, element);
        }
    }
}
