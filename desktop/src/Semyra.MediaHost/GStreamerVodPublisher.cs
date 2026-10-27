using System.Diagnostics;

namespace Semyra.MediaHost;

internal sealed class GStreamerVodPublisher : IMediaHostPublisher
{
    private const int GstFormatTime = 3;
    private const int SeekFlushAccurate = 1 | 2;
    private const ulong Second = 1_000_000_000;
    private const ulong MessageEos = 1UL << 0;
    private const ulong MessageError = 1UL << 1;
    private const ulong MessageStateChanged = 1UL << 6;
    private const ulong MessageAsyncDone = 1UL << 21;
    private const ulong SeekTimeout = 10 * Second;
    private const ulong AsyncDoneTimeout = 2 * Second;
    private const int RtpContinuityTimeoutMs = 10_000;
    private const int SeekConfirmationTimeoutMs = 2_000;
    private const uint VideoFrameTicks = 3_750;
    private const uint AudioFrameTicks = 960;
    private readonly IGStreamerApi _gst;
    private IntPtr _pipeline;
    private IntPtr _publishPipeline;
    private IntPtr _bus;
    private IntPtr _publishBus;
    private string? _sessionId;
    private Uri? _source;
    private Uri? _whip;
    private string _state = "idle";
    private string? _error;
    private long? _pausedPositionMs;
    private int _seekInProgress;
#if DEBUG
    private static readonly bool DebugTimings = string.Equals(
        Environment.GetEnvironmentVariable("SEMYRA_MEDIA_HOST_DEBUG_TIMINGS"),
        "1",
        StringComparison.Ordinal);
#else
    private static readonly bool DebugTimings = false;
#endif

    internal GStreamerVodPublisher(IGStreamerApi? gst = null) => _gst = gst ?? new GStreamerApi();

    public MediaHostSnapshot Start(string sessionId, Uri source, Uri whip)
    {
        Stop();
        _gst.Init();
        _source = source;
        _whip = whip;
        _sessionId = sessionId;
        if (!CreatePipelines(sessionId, source, whip, 0, 0))
        {
            Stop();
            return Failure(sessionId, "pipeline_parse_failed");
        }
        var result = _gst.SetState(_pipeline, GstState.Playing);
        if (result == GstStateChangeReturn.Failure)
        {
            Stop();
            return Failure(sessionId, "pipeline_start_failed");
        }
        result = _gst.GetState(_pipeline, out var state, out _, 15 * Second);
        if (result == GstStateChangeReturn.Failure)
        {
            Stop();
            return Failure(sessionId, "pipeline_start_failed");
        }
        if (_gst.SetState(_publishPipeline, GstState.Playing) == GstStateChangeReturn.Failure
            || _gst.GetState(_publishPipeline, out var publishState, out _, 15 * Second) == GstStateChangeReturn.Failure
            || publishState != GstState.Playing)
        {
            Stop();
            return Failure(sessionId, "pipeline_start_failed");
        }
        _state = state == GstState.Paused ? "paused" : "playing";
        return Snapshot();
    }

    public MediaHostSnapshot Pause(string sessionId) => Set(sessionId, GstState.Paused, "paused");
    public MediaHostSnapshot Play(string sessionId) => Set(sessionId, GstState.Playing, "playing");

    public MediaHostSnapshot Seek(string sessionId, long positionMs)
    {
        if (!Matches(sessionId)) return Failure(sessionId, "stale_session");
        if (Interlocked.Exchange(ref _seekInProgress, 1) != 0) return CurrentFailure("seek_in_progress");
        var timing = Stopwatch.StartNew();
        Timing("seek_command_received", timing);
        try
        {
            PollBus();
            if (_error is not null) return Snapshot();
            var previousState = _state;
            var videoBefore = _gst.ReadPayloaderStats(_publishPipeline, "video_pay");
            var audioBefore = _gst.ReadPayloaderStats(_publishPipeline, "audio_pay");
            uint? videoOffset = videoBefore is null ? null : unchecked(videoBefore.Timestamp + VideoFrameTicks);
            uint? audioOffset = audioBefore is null ? null : unchecked(audioBefore.Timestamp + AudioFrameTicks);
            if (videoBefore is null || audioBefore is null || videoOffset is null || audioOffset is null
                || _source is null || _whip is null) return CurrentFailure("seek_timeline_unavailable");
            Timing("pipeline_teardown_start", timing);
            ReleasePipeline();
            if (!CreatePipelines(sessionId, _source, _whip, videoOffset.Value, audioOffset.Value))
                return RepublishFailure("seek_republish_failed");
            if (_gst.SetState(_pipeline, GstState.Playing) == GstStateChangeReturn.Failure)
                return RepublishFailure("seek_republish_failed");
            var startResult = _gst.GetState(_pipeline, out var started, out _, SeekTimeout);
            if (startResult == GstStateChangeReturn.Failure || started != GstState.Playing)
                return RepublishFailure("seek_republish_failed");
            if (_gst.SetState(_publishPipeline, GstState.Playing) == GstStateChangeReturn.Failure
                || _gst.GetState(_publishPipeline, out var published, out _, SeekTimeout) == GstStateChangeReturn.Failure
                || published != GstState.Playing)
                return RepublishFailure("seek_republish_failed");
            Timing("new_pipeline_playing", timing);
            if (!WaitForSeekablePipeline()) return RepublishFailure("seek_preroll_timeout");
            if (_gst.SetState(_pipeline, GstState.Paused) == GstStateChangeReturn.Failure)
                return RepublishFailure("seek_preroll_failed");
            var pauseResult = _gst.GetState(_pipeline, out var paused, out _, SeekTimeout);
            if (pauseResult == GstStateChangeReturn.Failure || paused != GstState.Paused)
                return RepublishFailure("seek_preroll_failed");
            Drain(MessageAsyncDone);
            if (!_gst.SeekSimple(_pipeline, GstFormatTime, SeekFlushAccurate, checked(positionMs * 1_000_000)))
                return RepublishFailure("seek_failed");
            var asyncDone = _gst.BusTimedPopFiltered(_bus, AsyncDoneTimeout, MessageAsyncDone);
            if (asyncDone == IntPtr.Zero)
            {
                PollBus();
                if (_error is not null) return RepublishFailure(_error);
            }
            else _gst.MiniObjectUnref(asyncDone);
            PollBus();
            if (_error is not null) return RepublishFailure(_error);
            if (_gst.SetState(_pipeline, GstState.Playing) == GstStateChangeReturn.Failure)
                return RepublishFailure("seek_state_failed");
            var stateResult = _gst.GetState(_pipeline, out var actual, out _, SeekTimeout);
            if (stateResult == GstStateChangeReturn.Failure) return RepublishFailure("seek_state_failed");
            if (actual != GstState.Playing)
            {
                if (_gst.SetState(_pipeline, GstState.Playing) == GstStateChangeReturn.Failure
                    || _gst.GetState(_pipeline, out actual, out _, SeekTimeout) == GstStateChangeReturn.Failure
                    || actual != GstState.Playing) return RepublishFailure("seek_state_failed");
            }
            _state = "playing";
            if (!WaitForSeekPosition(positionMs)) return RepublishFailure("seek_timeout");
            if (!WaitForRtpContinuity(videoBefore, audioBefore, timing))
                return RepublishFailure("seek_rtp_timeout");
            if (previousState == "paused")
            {
                var pausedResult = Set(sessionId, GstState.Paused, "paused");
                if (!pausedResult.Ok) return RepublishFailure(pausedResult.ErrorCode ?? "seek_state_failed");
                _pausedPositionMs = positionMs;
            }
            else _pausedPositionMs = null;
            Timing("native_confirmation", timing);
            return Snapshot();
        }
        finally
        {
            Volatile.Write(ref _seekInProgress, 0);
        }
    }

    public MediaHostSnapshot Snapshot(string? sessionId = null)
    {
        if (_pipeline == IntPtr.Zero || _sessionId is null) return Failure(sessionId, "session_required");
        if (sessionId is not null && !Matches(sessionId)) return Failure(sessionId, "stale_session");
        PollBus();
        _ = _gst.QueryPosition(_pipeline, GstFormatTime, out var position);
        _ = _gst.QueryDuration(_pipeline, GstFormatTime, out var duration);
        var positionMs = _state == "paused" && _pausedPositionMs.HasValue
            ? _pausedPositionMs.Value
            : NsToMs(position);
        return new MediaHostSnapshot(
            _error is null,
            _state,
            positionMs,
            NsToMs(duration),
            duration > 0,
            _sessionId,
            _error,
            _gst.ReadPayloaderStats(_publishPipeline, "video_pay"),
            _gst.ReadPayloaderStats(_publishPipeline, "audio_pay"));
    }

    public MediaHostSnapshot Stop(string? sessionId = null)
    {
        if (sessionId is not null && _sessionId is not null && !Matches(sessionId)) return Failure(sessionId, "stale_session");
        var previous = _sessionId ?? sessionId;
        ReleasePipeline();
        _sessionId = null; _state = "idle"; _error = null; _pausedPositionMs = null;
        _source = null; _whip = null;
        return new MediaHostSnapshot(true, "idle", 0, 0, false, previous);
    }

    private MediaHostSnapshot Set(string sessionId, GstState target, string state)
    {
        if (!Matches(sessionId)) return Failure(sessionId, "stale_session");
        PollBus();
        if (_error is not null) return Snapshot();
        if (string.Equals(_state, state, StringComparison.Ordinal)) return Snapshot();
        var videoBefore = DebugTimings && target == GstState.Playing
            ? _gst.ReadPayloaderStats(_publishPipeline, "video_pay")
            : null;
        var audioBefore = DebugTimings && target == GstState.Playing
            ? _gst.ReadPayloaderStats(_publishPipeline, "audio_pay")
            : null;
        DebugState($"before_{state}", null, null, null);
        var setResult = _gst.SetState(_pipeline, target);
        if (setResult == GstStateChangeReturn.Failure) return Failure(sessionId, "state_change_failed");
        var result = _gst.GetState(_pipeline, out var actual, out var pending, 10 * Second);
        DebugState($"after_{state}", setResult, actual, pending);
        if (result == GstStateChangeReturn.Failure || actual != target) return Failure(sessionId, "state_change_failed");
        if (target == GstState.Playing) DebugResumeArrival(videoBefore, audioBefore);
        _state = state;
        if (state == "paused")
        {
            _ = _gst.QueryPosition(_pipeline, GstFormatTime, out var position);
            _pausedPositionMs = NsToMs(position);
        }
        else
        {
            _pausedPositionMs = null;
        }
        return Snapshot();
    }

    private void PollBus()
    {
        PollBus(_bus, content: true);
        PollBus(_publishBus, content: false);
    }

    private void PollBus(IntPtr bus, bool content)
    {
        if (bus == IntPtr.Zero) return;
        var error = _gst.BusTimedPopFiltered(bus, 0, MessageError);
        if (error != IntPtr.Zero)
        {
            if (DebugTimings) Console.Error.WriteLine($"media_host_timing phase=pipeline_error pipeline={(content ? "content" : "publish")}");
            _gst.MiniObjectUnref(error); _error = "pipeline_error"; _state = "failed";
        }
        if (content)
        {
            var eos = _gst.BusTimedPopFiltered(bus, 0, MessageEos);
            if (eos != IntPtr.Zero) { _gst.MiniObjectUnref(eos); _state = "ended"; }
        }
        Drain(bus, MessageStateChanged);
        Drain(bus, MessageAsyncDone);
    }

    private void Drain(ulong messageType)
    {
        Drain(_bus, messageType);
    }

    private void Drain(IntPtr bus, ulong messageType)
    {
        if (bus == IntPtr.Zero) return;
        IntPtr message;
        while ((message = _gst.BusTimedPopFiltered(bus, 0, messageType)) != IntPtr.Zero)
            _gst.MiniObjectUnref(message);
    }

    private void ReleasePipeline()
    {
        SetNullAndWait(_publishPipeline);
        SetNullAndWait(_pipeline);
        if (_bus != IntPtr.Zero) { _gst.ObjectUnref(_bus); _bus = IntPtr.Zero; }
        if (_publishBus != IntPtr.Zero) { _gst.ObjectUnref(_publishBus); _publishBus = IntPtr.Zero; }
        if (_pipeline != IntPtr.Zero) { _gst.ObjectUnref(_pipeline); _pipeline = IntPtr.Zero; }
        if (_publishPipeline != IntPtr.Zero) { _gst.ObjectUnref(_publishPipeline); _publishPipeline = IntPtr.Zero; }
    }

    private void SetNullAndWait(IntPtr pipeline)
    {
        if (pipeline == IntPtr.Zero || _gst.SetState(pipeline, GstState.Null) == GstStateChangeReturn.Failure) return;
        _ = _gst.GetState(pipeline, out _, out _, SeekTimeout);
    }

    private bool WaitForRtpContinuity(
        RtpPayloaderStats videoBefore,
        RtpPayloaderStats audioBefore,
        Stopwatch timing)
    {
        var deadline = Environment.TickCount64 + RtpContinuityTimeoutMs;
        var videoReported = false;
        var audioReported = false;
        do
        {
            PollBus();
            if (_error is not null) return false;
            var video = _gst.ReadPayloaderStats(_publishPipeline, "video_pay");
            var audio = _gst.ReadPayloaderStats(_publishPipeline, "audio_pay");
            var videoAdvanced = video is not null && StreamAdvanced(video, videoBefore);
            var audioAdvanced = audio is not null && StreamAdvanced(audio, audioBefore);
            if (videoAdvanced && !videoReported) { Timing("first_video_rtp", timing); videoReported = true; }
            if (audioAdvanced && !audioReported) { Timing("first_audio_rtp", timing); audioReported = true; }
            if (videoAdvanced && audioAdvanced) return true;
            Thread.Sleep(10);
        }
        while (Environment.TickCount64 < deadline);
        return false;
    }

    private bool WaitForSeekablePipeline()
    {
        var deadline = Environment.TickCount64 + RtpContinuityTimeoutMs;
        do
        {
            PollBus();
            if (_error is not null) return false;
            var video = _gst.ReadPayloaderStats(_publishPipeline, "video_pay");
            var audio = _gst.ReadPayloaderStats(_publishPipeline, "audio_pay");
            if (_gst.QueryDuration(_pipeline, GstFormatTime, out var duration) && duration > 0
                && _gst.QueryPosition(_pipeline, GstFormatTime, out var position) && position >= 500_000_000
                && video is not null && video.RunningTimeNs != ulong.MaxValue
                && audio is not null && audio.RunningTimeNs != ulong.MaxValue) return true;
            Thread.Sleep(10);
        }
        while (Environment.TickCount64 < deadline);
        return false;
    }

    private bool WaitForSeekPosition(long positionMs)
    {
        var deadline = Environment.TickCount64 + SeekConfirmationTimeoutMs;
        long observedPosition = -1;
        do
        {
            PollBus();
            if (_error is not null) return false;
            if (_gst.QueryPosition(_pipeline, GstFormatTime, out var position)
                && Math.Abs(NsToMs(position) - positionMs) <= 1_000) return true;
            observedPosition = position;
            Thread.Sleep(10);
        }
        while (Environment.TickCount64 < deadline);
        if (DebugTimings)
            Console.Error.WriteLine($"media_host_timing phase=seek_position_timeout requested_ms={positionMs} observed_ms={NsToMs(observedPosition)}");
        return false;
    }

    private static bool Advanced(uint current, uint previous) => unchecked((int)(current - previous)) > 0;
    private static bool StreamAdvanced(RtpPayloaderStats current, RtpPayloaderStats previous) =>
        current.Ssrc != previous.Ssrc
            ? current.RunningTimeNs != ulong.MaxValue
            : Advanced(current.Seqnum, previous.Seqnum) && Advanced(current.Timestamp, previous.Timestamp);
    private static void Timing(string phase, Stopwatch timing)
    {
        if (DebugTimings) Console.Error.WriteLine($"media_host_timing phase={phase} elapsed_ms={timing.ElapsedMilliseconds}");
    }

    private void DebugState(
        string phase,
        GstStateChangeReturn? stateChangeReturn,
        GstState? currentState,
        GstState? pendingState)
    {
        if (!DebugTimings) return;
        var pipeline = _gst.ReadPipelineDebugStats(_pipeline);
        var video = _gst.ReadPayloaderStats(_publishPipeline, "video_pay");
        var audio = _gst.ReadPayloaderStats(_publishPipeline, "audio_pay");
        var videoMs = video is null || video.RunningTimeNs == ulong.MaxValue ? -1d : video.RunningTimeNs / 1_000_000d;
        var audioMs = audio is null || audio.RunningTimeNs == ulong.MaxValue ? -1d : audio.RunningTimeNs / 1_000_000d;
        var deltaMs = videoMs < 0 || audioMs < 0 ? -1d : audioMs - videoMs;
        _ = _gst.QueryPosition(_pipeline, GstFormatTime, out var position);
        Console.Error.WriteLine(string.Join(' ',
            "media_host_state",
            $"phase={phase}",
            $"state_change_return={stateChangeReturn?.ToString().ToLowerInvariant() ?? "none"}",
            $"current_state={currentState?.ToString().ToLowerInvariant() ?? _state}",
            $"pending_state={pendingState?.ToString().ToLowerInvariant() ?? "none"}",
            $"clock_generation={pipeline?.ClockGeneration ?? 0}",
            $"clock_time_ms={NsToDebugMs(pipeline?.ClockTimeNs)}",
            $"base_time_ms={NsToDebugMs(pipeline?.BaseTimeNs)}",
            $"start_time_ms={NsToDebugMs(pipeline?.StartTimeNs)}",
            $"running_time_ms={NsToDebugMs(pipeline?.RunningTimeNs)}",
            $"position_ms={NsToMs(position)}",
            $"video_rt_ms={videoMs:F3}",
            $"audio_rt_ms={audioMs:F3}",
            $"av_delta_ms={deltaMs:F3}",
            QueueFields("source", pipeline?.SourceQueue),
            QueueFields("video", pipeline?.VideoQueue),
            QueueFields("audio", pipeline?.AudioQueue)));
    }

    private void DebugResumeArrival(RtpPayloaderStats? videoBefore, RtpPayloaderStats? audioBefore)
    {
        if (!DebugTimings || videoBefore is null || audioBefore is null) return;
        var timer = Stopwatch.StartNew();
        long? videoMs = null;
        long? audioMs = null;
        while (timer.ElapsedMilliseconds <= 2_000 && (videoMs is null || audioMs is null))
        {
            var video = _gst.ReadPayloaderStats(_publishPipeline, "video_pay");
            var audio = _gst.ReadPayloaderStats(_publishPipeline, "audio_pay");
            if (videoMs is null && video is not null && StreamAdvanced(video, videoBefore)) videoMs = timer.ElapsedMilliseconds;
            if (audioMs is null && audio is not null && StreamAdvanced(audio, audioBefore)) audioMs = timer.ElapsedMilliseconds;
            if (videoMs is null || audioMs is null) Thread.Sleep(1);
        }
        var delta = videoMs.HasValue && audioMs.HasValue ? audioMs.Value - videoMs.Value : long.MinValue;
        Console.Error.WriteLine(
            $"media_host_timing phase=resume_rtp_arrival video_ms={videoMs?.ToString() ?? "-1"} audio_ms={audioMs?.ToString() ?? "-1"} delta_ms={(delta == long.MinValue ? "-1" : delta.ToString())}");
    }

    private static string QueueFields(string name, QueueDebugStats? queue) =>
        $"{name}_queue_time_ms={NsToDebugMs(queue?.CurrentLevelTimeNs)} {name}_queue_buffers={queue?.CurrentLevelBuffers ?? 0} {name}_queue_bytes={queue?.CurrentLevelBytes ?? 0}";
    private static string NsToDebugMs(ulong? value) => value.HasValue && value.Value != ulong.MaxValue
        ? (value.Value / 1_000_000d).ToString("F3", System.Globalization.CultureInfo.InvariantCulture)
        : "-1";

    private bool CreatePipelines(
        string sessionId,
        Uri source,
        Uri whip,
        uint videoTimestampOffset,
        uint audioTimestampOffset)
    {
        var channelKey = $"semyra_{sessionId}";
        _pipeline = _gst.ParseLaunch(BuildContentPipelineDescription(source, channelKey), out var contentError);
        if (_pipeline == IntPtr.Zero || contentError != IntPtr.Zero)
        {
            if (contentError != IntPtr.Zero) _gst.ErrorFree(contentError);
            return false;
        }
        _publishPipeline = _gst.ParseLaunch(
            BuildPublishPipelineDescription(whip, channelKey, videoTimestampOffset, audioTimestampOffset),
            out var publishError);
        if (_publishPipeline == IntPtr.Zero || publishError != IntPtr.Zero)
        {
            if (publishError != IntPtr.Zero) _gst.ErrorFree(publishError);
            return false;
        }
        _bus = _gst.GetBus(_pipeline);
        _publishBus = _gst.GetBus(_publishPipeline);
        return _bus != IntPtr.Zero && _publishBus != IntPtr.Zero;
    }

    private bool Matches(string sessionId) => _pipeline != IntPtr.Zero && string.Equals(_sessionId, sessionId, StringComparison.Ordinal);
    private MediaHostSnapshot Failure(string? sessionId, string code) => new(false, "failed", 0, 0, false, sessionId, code);
    private MediaHostSnapshot CurrentFailure(string code)
    {
        _ = _gst.QueryPosition(_pipeline, GstFormatTime, out var position);
        _ = _gst.QueryDuration(_pipeline, GstFormatTime, out var duration);
        return new(false, _state, NsToMs(position), NsToMs(duration), duration > 0, _sessionId, code);
    }
    private MediaHostSnapshot RepublishFailure(string code)
    {
        ReleasePipeline();
        _state = "failed";
        _error = code;
        return new(false, _state, 0, 0, false, _sessionId, code);
    }
    private static long NsToMs(long value) => value <= 0 ? 0 : value / 1_000_000;
    private static string Quote(Uri value) => $"\"{value.AbsoluteUri.Replace("\\", "\\\\", StringComparison.Ordinal).Replace("\"", "\\\"", StringComparison.Ordinal)}\"";
    private static string Quote(string value) => $"\"{value.Replace("\\", "\\\\", StringComparison.Ordinal).Replace("\"", "\\\"", StringComparison.Ordinal)}\"";
    internal static string BuildContentPipelineDescription(Uri source, string channelKey) => string.Join(' ',
        "souphttpsrc", $"location={Quote(source)}", "! queue name=source_queue max-size-time=3000000000 ! qtdemux name=demux",
        "demux. ! queue name=video_queue max-size-time=3000000000 ! h264parse ! avdec_h264 ! videoconvert !",
        $"intervideosink channel={Quote(channelKey + "_video")} sync=true",
        "demux. ! queue name=audio_queue ! aacparse ! avdec_aac ! audioconvert ! audioresample ! audio/x-raw,format=S16LE,rate=48000,channels=2,layout=interleaved",
        DebugIdentity("audio_1_before_bridge"), "!",
        $"interaudiosink channel={Quote(channelKey + "_audio")} sync=true");

    internal static string BuildPublishPipelineDescription(Uri whip, string channelKey, uint videoTimestampOffset = 0, uint audioTimestampOffset = 0) => string.Join(' ',
        "whipsink name=whip", $"whip-endpoint={Quote(whip)}",
        $"intervideosrc channel={Quote(channelKey + "_video")} timeout=18446744073709551615 ! videoconvert !",
        "x264enc bitrate=2800 speed-preset=veryfast tune=zerolatency bframes=0 key-int-max=48 cabac=false dct8x8=false byte-stream=true !",
        "h264parse config-interval=-1 ! video/x-h264,stream-format=byte-stream,alignment=au,profile=constrained-baseline ! clocksync ! identity name=video_timeline single-segment=true !",
        $"rtph264pay name=video_pay perfect-rtptime=false timestamp-offset={videoTimestampOffset} config-interval=-1 aggregate-mode=zero-latency pt=97 ! application/x-rtp,media=video,encoding-name=H264,payload=97,clock-rate=90000,packetization-mode=(string)1 ! whip.sink_0",
        $"interaudiosrc channel={Quote(channelKey + "_audio")} buffer-time=200000000 latency-time=40000000 period-time=10000000",
        DebugIdentity("audio_2_after_bridge"),
        "! audioconvert ! audioresample ! audio/x-raw,format=S16LE,rate=48000,channels=2,layout=interleaved",
        DebugIdentity("audio_3_before_opus"), "! opusenc bitrate=96000",
        DebugIdentity("audio_4_before_rtp"),
        $"! clocksync ! identity name=audio_timeline single-segment=true ! rtpopuspay name=audio_pay perfect-rtptime=false timestamp-offset={audioTimestampOffset} pt=96 ! application/x-rtp,media=audio,encoding-name=OPUS,payload=96,clock-rate=48000,encoding-params=(string)2 ! whip.sink_1");

    private static string DebugIdentity(string name) => DebugTimings
        ? $"! identity name={name} silent=false check-imperfect-timestamp=true check-imperfect-offset=true"
        : string.Empty;

    internal static string BuildPipelineDescription(Uri source, Uri whip, uint videoTimestampOffset = 0, uint audioTimestampOffset = 0) =>
        BuildContentPipelineDescription(source, "semyra_test") + " "
        + BuildPublishPipelineDescription(whip, "semyra_test", videoTimestampOffset, audioTimestampOffset);

    public void Dispose() => Stop();
}
