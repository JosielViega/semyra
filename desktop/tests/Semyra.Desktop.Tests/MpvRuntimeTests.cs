using Semyra.Desktop.Media;

namespace Semyra.Desktop.Tests;

public sealed class MpvRuntimeTests
{
    [Fact]
    public void DiscoveryPrefersApplicationRuntimeAndRecordsSafeVersion()
    {
        var root = Path.Combine(Path.GetTempPath(), "semyra-mpv-runtime-" + Guid.NewGuid().ToString("N"));
        var executable = Path.Combine(root, "runtime", "mpv", "mpv.exe");
        Directory.CreateDirectory(Path.GetDirectoryName(executable)!);
        File.WriteAllBytes(executable, []);
        try
        {
            var runtime = MpvRuntime.DiscoverDefault(root, _ => null, path => path == executable ? "mpv test" : null);
            Assert.True(runtime.Available);
            Assert.Equal(Path.GetFullPath(executable), runtime.ExecutablePath);
            Assert.Equal("app-runtime", runtime.Origin);
            Assert.Equal("mpv test", runtime.Version);
        }
        finally
        {
            Directory.Delete(root, true);
        }
    }

    [Theory]
    [InlineData(false)]
    [InlineData(true)]
    public void SemyraMpvHomeResolvesDirectoryOrDirectExecutable(bool directExecutable)
    {
        var root = Path.Combine(Path.GetTempPath(), "semyra-mpv-home-" + Guid.NewGuid().ToString("N"));
        var executable = Path.Combine(root, "mpv.exe");
        Directory.CreateDirectory(root);
        File.WriteAllBytes(executable, []);
        try
        {
            var configured = directExecutable ? executable : root;
            var runtime = MpvRuntime.DiscoverDefault(
                Path.Combine(root, "application"),
                name => name == "SEMYRA_MPV_HOME" ? configured : null,
                path => path == executable ? "mpv home test" : null);

            Assert.True(runtime.Available);
            Assert.Equal(Path.GetFullPath(executable), runtime.ExecutablePath);
            Assert.Equal("SEMYRA_MPV_HOME", runtime.Origin);
        }
        finally
        {
            Directory.Delete(root, true);
        }
    }

    [Fact]
    public void ProcessArgumentsContainOnlyRuntimeOptionsAndRandomPipe()
    {
        const string providerUrl = "https://example.invalid/private/video.mp4";
        var runtime = new MpvRuntime(true, "mpv.exe", "mpv test", "test", null);
        var first = new MpvProcess(runtime, (nint)123);
        var second = new MpvProcess(runtime, (nint)123);
        var info = first.BuildStartInfo();

        Assert.NotEqual(first.PipeName, second.PipeName);
        Assert.StartsWith("semyra-mpv-", first.PipeName);
        Assert.DoesNotContain(first.Arguments, value => value.Contains(providerUrl, StringComparison.Ordinal));
        Assert.DoesNotContain(info.Environment, pair => pair.Value?.Contains(providerUrl, StringComparison.Ordinal) == true);
        Assert.Contains("--idle=yes", first.Arguments);
        Assert.Contains("--wid=123", first.Arguments);
        Assert.Contains(first.Arguments, value => value.EndsWith(first.PipeName, StringComparison.Ordinal));
        Assert.False(info.UseShellExecute);
    }

    [Fact]
    public void IpcCommandSerializationIsStrictAndLoggingIsNotRequired()
    {
        var payload = MpvProcess.SerializeCommand("set_property", ["volume", 42], 7);
        Assert.Equal("{\"command\":[\"set_property\",\"volume\",42],\"request_id\":7}", payload);
        Assert.DoesNotContain("provider", payload, StringComparison.OrdinalIgnoreCase);
    }

    [Fact]
    public void LoadfileSerializationKeepsReplaceAndProviderUrlOnlyInIpcPayload()
    {
        const string providerUrl = "https://example.invalid/private/video.mp4?credential=hidden";
        var payload = MpvProcess.SerializeCommand("loadfile", [providerUrl, "replace"], 9);

        Assert.Equal("{\"command\":[\"loadfile\",\"https://example.invalid/private/video.mp4?credential=hidden\",\"replace\"],\"request_id\":9}", payload);
        Assert.DoesNotContain(providerUrl, MpvProcess.BuildArguments((nint)123, "pipe"));
    }
}
