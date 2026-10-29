using System.Net;
using System.Text;
using System.Text.Json;
using System.Windows.Media;
using System.Windows.Media.Imaging;
using Semyra.Desktop.Desktop;
using Semyra.Desktop.Host;
using Semyra.Desktop.Media;
using Semyra.Desktop.Screen;

namespace Semyra.Desktop.Tests;

public sealed class ScreenPipelineTests
{
    [Theory]
    [InlineData("inactive")]
    [InlineData("buffering")]
    [InlineData("publishing")]
    [InlineData("error")]
    [InlineData("complete")]
    public async Task DesktopPublishStatusAcceptsOnlyContractStates(string state)
    {
        using var http = new HttpClient(new StatusHandler(_ => Json(HttpStatusCode.OK, $"{{\"state\":\"{state}\"}}")));
        var client = new DesktopPublishClient(() => new Uri("https://semyra.invalid/"), http);

        var result = await client.StatusAsync(Authorization(), "INGRESS_TEST", CancellationToken.None);

        Assert.Equal(state, result.State);
    }

    [Theory]
    [InlineData(HttpStatusCode.NotFound, "{\"error\":\"ingress_not_found\"}", "ingress_not_found")]
    [InlineData(HttpStatusCode.ServiceUnavailable, "{\"error\":\"ingress_status_failed\"}", "ingress_status_request_failed")]
    [InlineData(HttpStatusCode.Unauthorized, "{\"error\":\"host_session_expired\"}", "host_authorization_expired")]
    [InlineData(HttpStatusCode.Unauthorized, "{\"error\":\"invalid_host_session\"}", "host_authorization_invalid")]
    [InlineData(HttpStatusCode.Unauthorized, "{\"error\":\"host_session_required\"}", "host_authorization_required")]
    [InlineData(HttpStatusCode.Conflict, "{\"error\":\"transmission_changed\"}", "transmission_changed")]
    public async Task DesktopPublishStatusPreservesSanitizedFailureSemantics(
        HttpStatusCode status, string body, string expected)
    {
        using var http = new HttpClient(new StatusHandler(_ => Json(status, body)));
        var client = new DesktopPublishClient(() => new Uri("https://semyra.invalid/"), http);

        var error = await Assert.ThrowsAsync<MediaEngineException>(() =>
            client.StatusAsync(Authorization(), "INGRESS_TEST", CancellationToken.None));

        Assert.Equal(expected, error.Code);
    }

    [Theory]
    [InlineData("not-json")]
    [InlineData("{\"state\":\"unknown\"}")]
    [InlineData("{}")]
    public async Task DesktopPublishStatusRejectsInvalidSuccessfulPayload(string body)
    {
        using var http = new HttpClient(new StatusHandler(_ => Json(HttpStatusCode.OK, body)));
        var client = new DesktopPublishClient(() => new Uri("https://semyra.invalid/"), http);

        var error = await Assert.ThrowsAsync<MediaEngineException>(() =>
            client.StatusAsync(Authorization(), "INGRESS_TEST", CancellationToken.None));

        Assert.Equal("ingress_status_invalid", error.Code);
    }

    [Theory]
    [InlineData("ingress_not_found", true, "screen_ingress_not_found")]
    [InlineData("ingress_status_request_failed", true, "screen_ingress_status_request_failed")]
    [InlineData("ingress_status_invalid", false, "screen_ingress_status_invalid")]
    [InlineData("transmission_changed", false, "screen_transmission_changed")]
    [InlineData("host_authorization_expired", false, "screen_authorization_expired")]
    [InlineData("host_authorization_invalid", false, "screen_authorization_rejected")]
    [InlineData("host_authorization_required", false, "screen_authorization_required")]
    public void ScreenReadinessRetriesOnlyTransientFailuresAndMapsSanitizedCodes(
        string code, bool transient, string expected)
    {
        Assert.Equal(transient, ScreenPublisher.IsTransientStatusError(code));
        Assert.Equal(expected, ScreenPublisher.MapStatusError(code));
    }

    [Fact]
    public void ScreenReadinessRetryBudgetIsFinite()
    {
        Assert.True(ScreenPublisher.CanRetryStatusError("ingress_not_found", 1));
        Assert.True(ScreenPublisher.CanRetryStatusError("ingress_status_request_failed", 7));
        Assert.False(ScreenPublisher.CanRetryStatusError("ingress_not_found", 8));
        Assert.False(ScreenPublisher.CanRetryStatusError("ingress_status_invalid", 1));
    }

    [Theory]
    [InlineData(HttpStatusCode.OK, false)]
    [InlineData(HttpStatusCode.NotFound, false)]
    [InlineData(HttpStatusCode.ServiceUnavailable, true)]
    public async Task DesktopPublishCleanupIsIdempotentAndReportsRetryableFailure(
        HttpStatusCode status, bool throws)
    {
        using var http = new HttpClient(new StatusHandler(_ => Json(status, "{}")));
        var client = new DesktopPublishClient(() => new Uri("https://semyra.invalid/"), http);

        if (throws)
        {
            var error = await Assert.ThrowsAsync<MediaEngineException>(() =>
                client.StopAsync(Authorization(), "INGRESS_TEST", CancellationToken.None));
            Assert.Equal("ingress_cleanup_failed", error.Code);
        }
        else
        {
            await client.StopAsync(Authorization(), "INGRESS_TEST", CancellationToken.None);
        }
    }

    [Theory]
    [InlineData("economy", "video/x-raw,width=1280,height=720,framerate=15/1,pixel-aspect-ratio=1/1", "bitrate=1500")]
    [InlineData("standard", "video/x-raw,width=1280,height=720,framerate=30/1,pixel-aspect-ratio=1/1", "bitrate=2800")]
    [InlineData("high", "video/x-raw,width=1920,height=1080,framerate=30/1,pixel-aspect-ratio=1/1", "bitrate=5000")]
    public void QualityProfilesBuildLowLatencyH264(string quality, string caps, string bitrate)
    {
        var target = new ScreenCaptureTarget(new string('a', 32), "monitor", "Monitor", 1920, 1080, (nint)123);
        var arguments = ScreenPipeline.Arguments(target, quality, false, "x264enc", new Uri("https://unit.invalid/whip"));
        Assert.Contains(caps, arguments);
        Assert.Contains(bitrate, arguments);
        Assert.Contains("bframes=0", arguments);
        Assert.Contains("whipsink", arguments);
        Assert.DoesNotContain("wasapi2src", arguments);
    }

    [Fact]
    public void AudioUsesWasapiLoopbackFortyEightKhzStereoAndOpus()
    {
        var target = new ScreenCaptureTarget(new string('b', 32), "window", "Janela", 1280, 720, (nint)456);
        var arguments = ScreenPipeline.Arguments(target, "standard", true, "mfh264enc", new Uri("https://unit.invalid/whip"));
        Assert.Contains("capture-api=wgc", arguments);
        Assert.Contains("window-handle=456", arguments);
        Assert.Contains("wasapi2src", arguments);
        Assert.Contains("loopback=true", arguments);
        Assert.Contains("audio/x-raw,rate=48000,channels=2", arguments);
        Assert.Contains("bitrate=128000", arguments);
        Assert.Contains("opusenc", arguments);
    }

    [Theory]
    [InlineData(3440, 1440, 1920, 1080, 1920, 802)]
    [InlineData(800, 600, 1280, 720, 800, 600)]
    [InlineData(1920, 1080, 1280, 720, 1280, 720)]
    public void ScalingPreservesAspectAndNeverUpscales(int width, int height, int maxWidth, int maxHeight,
        int expectedWidth, int expectedHeight)
    {
        Assert.Equal((expectedWidth, expectedHeight), ScreenPipeline.ScaledDimensions(width, height, maxWidth, maxHeight));
    }

    [Fact]
    public void ScreenCapabilitiesAreSeparateFromIptvPublishing()
    {
        var snapshot = HostEngineSnapshot.Create(HostEngineState.Ready, null, true,
            whipAvailable: false, localViewAvailable: false, vodPublishAvailable: false,
            screenPublishAvailable: true, screenAudioAvailable: true);
        Assert.Contains("screen.targets", snapshot.Capabilities);
        Assert.Contains("screen.publish", snapshot.Capabilities);
        Assert.Contains("screen.audio", snapshot.Capabilities);
        Assert.DoesNotContain("livekit.publish", snapshot.Capabilities);
    }

    [Fact]
    public void PublisherStateEventContainsOnlySanitizedScreenState()
    {
        var snapshot = new ScreenPublisherSnapshot("failed", new string('a', 32), "monitor", "standard", true,
            "mfh264enc", "screen_pipeline_exited");
        var json = DesktopBridge.CreateScreenPublisherStateEvent(snapshot, new string('b', 32));
        using var document = JsonDocument.Parse(json);

        Assert.Equal(DesktopMessage.ScreenPublishStateType, document.RootElement.GetProperty("type").GetString());
        Assert.Equal("screen_pipeline_exited", document.RootElement.GetProperty("errorCode").GetString());
        Assert.DoesNotContain("handle", json, StringComparison.OrdinalIgnoreCase);
        Assert.DoesNotContain("whip", json, StringComparison.OrdinalIgnoreCase);
        Assert.DoesNotContain("token", json, StringComparison.OrdinalIgnoreCase);
    }

    [Fact]
    public void TargetRegistryUsesOpaqueIdsAndFencesAccountContext()
    {
        var target = ScreenCaptureTargetRegistry.Create("monitor", "Monitor principal", 1920, 1080, (nint)123);
        var registry = new ScreenCaptureTargetRegistry(() => [target], _ => true);
        var firstContext = new string('a', 32);
        var secondContext = new string('b', 32);

        var summary = Assert.Single(registry.Refresh(firstContext));
        Assert.Matches("^[a-f0-9]{32}$", summary.Id);
        Assert.DoesNotContain("123", summary.Id, StringComparison.Ordinal);
        Assert.Equal((nint)123, registry.Resolve(firstContext, summary.Id).Handle);
        Assert.Throws<ScreenPublisherException>(() => registry.Resolve(secondContext, summary.Id));

        registry.Invalidate();
        Assert.Throws<ScreenPublisherException>(() => registry.Resolve(firstContext, summary.Id));
    }

    [Fact]
    public void ClosedTargetIsRemovedBeforePipelineStart()
    {
        var target = ScreenCaptureTargetRegistry.Create("window", "Janela", 1280, 720, (nint)456);
        var registry = new ScreenCaptureTargetRegistry(() => [target], _ => false);
        var context = new string('c', 32);
        var summary = Assert.Single(registry.Refresh(context));

        Assert.Throws<ScreenPublisherException>(() => registry.Resolve(context, summary.Id));
        Assert.Throws<ScreenPublisherException>(() => registry.Resolve(context, summary.Id));
    }

    [Theory]
    [InlineData("ERROR from wasapi2src: device is unavailable", "screen_audio_failed")]
    [InlineData("d3d11screencapturesrc failed to capture", "screen_capture_failed")]
    [InlineData("whipsink WebRTC negotiation failed", "screen_whip_failed")]
    [InlineData("nvh264enc encoder could not initialize", "screen_encoder_failed")]
    [InlineData("pipeline stopped", "screen_pipeline_exited")]
    public void DiagnosticsExposeOnlyStableSanitizedFailureCodes(string line, string expected)
    {
        var diagnostics = new ScreenPipelineDiagnostics();
        diagnostics.Observe(line);
        Assert.Equal(expected, diagnostics.ErrorCode());
    }

    [Theory]
    [InlineData(1920, 1080, 400, 225)]
    [InlineData(3440, 1440, 400, 167)]
    [InlineData(320, 200, 320, 200)]
    public void PreviewDimensionsAreBoundedAndPreserveAspectRatio(
        int width, int height, int expectedWidth, int expectedHeight)
    {
        Assert.Equal((expectedWidth, expectedHeight), ScreenPreviewCapture.PreviewDimensions(width, height));
    }

    [Fact]
    public void PreviewIsEncodedInMemoryAsBoundedJpeg()
    {
        var width = 800;
        var height = 450;
        var stride = width * 4;
        var pixels = new byte[stride * height];
        Array.Fill(pixels, (byte)72);
        var bitmap = BitmapSource.Create(width, height, 96, 96, PixelFormats.Bgra32, null, pixels, stride);
        bitmap.Freeze();

        var preview = ScreenPreviewCapture.Encode(bitmap);

        Assert.Equal(400, preview.Width);
        Assert.Equal(225, preview.Height);
        Assert.StartsWith("data:image/jpeg;base64,", preview.DataUrl, StringComparison.Ordinal);
        Assert.True(Convert.FromBase64String(preview.DataUrl["data:image/jpeg;base64,".Length..]).Length
            <= ScreenPreviewCapture.MaximumBytes);
    }

    [Fact]
    public async Task PreviewTimeoutIsSanitizedAndDoesNotPersistContent()
    {
        var target = ScreenCaptureTargetRegistry.Create("monitor", "Sensitive title", 1920, 1080, (nint)123);
        var preview = new ScreenPreviewCapture(
            _ => { Thread.Sleep(100); return new ScreenPreviewResult("data:image/jpeg;base64,ZmFrZQ==", 400, 225); },
            TimeSpan.FromMilliseconds(10));

        var error = await Assert.ThrowsAsync<ScreenPublisherException>(() =>
            preview.CaptureAsync(target, CancellationToken.None));

        Assert.Equal("screen_preview_timeout", error.Code);
        Assert.DoesNotContain("Sensitive", error.ToString(), StringComparison.Ordinal);
    }

    [Fact]
    public async Task PreviewHonorsCancellationWithoutReturningStaleImage()
    {
        var target = ScreenCaptureTargetRegistry.Create("window", "Window", 1280, 720, (nint)456);
        var preview = new ScreenPreviewCapture(
            _ => { Thread.Sleep(100); return new ScreenPreviewResult("data:image/jpeg;base64,ZmFrZQ==", 400, 225); });
        using var cancellation = new CancellationTokenSource(TimeSpan.FromMilliseconds(10));

        await Assert.ThrowsAnyAsync<OperationCanceledException>(() =>
            preview.CaptureAsync(target, cancellation.Token));
    }

    private static HostAuthorization Authorization() => new(
        new string('a', 32) + "." + new string('b', 64),
        "ROOM2345",
        new string('c', 32),
        3,
        "screen.publish",
        DateTimeOffset.UtcNow.AddMinutes(5));

    private static HttpResponseMessage Json(HttpStatusCode status, string body) => new(status)
    {
        Content = new StringContent(body, Encoding.UTF8, "application/json"),
    };

    private sealed class StatusHandler(Func<HttpRequestMessage, HttpResponseMessage> response) : HttpMessageHandler
    {
        protected override Task<HttpResponseMessage> SendAsync(
            HttpRequestMessage request,
            CancellationToken cancellationToken) => Task.FromResult(response(request));
    }
}
