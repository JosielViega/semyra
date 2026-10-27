using System.Runtime.InteropServices;

namespace Semyra.MediaHost;

internal enum GstState { VoidPending = 0, Null = 1, Ready = 2, Paused = 3, Playing = 4 }
internal enum GstStateChangeReturn { Failure = 0, Success = 1, Async = 2, NoPreroll = 3 }

internal sealed record QueueDebugStats(
    ulong CurrentLevelTimeNs,
    uint CurrentLevelBuffers,
    uint CurrentLevelBytes);

internal sealed record PipelineDebugStats(
    int ClockGeneration,
    ulong ClockTimeNs,
    ulong BaseTimeNs,
    ulong StartTimeNs,
    ulong RunningTimeNs,
    QueueDebugStats? SourceQueue,
    QueueDebugStats? VideoQueue,
    QueueDebugStats? AudioQueue);

[StructLayout(LayoutKind.Sequential)]
internal struct GValue
{
    internal nuint Type;
    internal ulong Data0;
    internal ulong Data1;
}

internal interface IGStreamerApi
{
    void Init();
    IntPtr ParseLaunch(string description, out IntPtr error);
    GstStateChangeReturn SetState(IntPtr element, GstState state);
    GstStateChangeReturn GetState(IntPtr element, out GstState state, out GstState pending, ulong timeout);
    bool SeekSimple(IntPtr element, int format, int seekFlags, long position);
    bool QueryPosition(IntPtr element, int format, out long position);
    bool QueryDuration(IntPtr element, int format, out long duration);
    IntPtr GetBus(IntPtr element);
    IntPtr BusTimedPopFiltered(IntPtr bus, ulong timeout, ulong types);
    void MiniObjectUnref(IntPtr message);
    void ObjectUnref(IntPtr value);
    void ErrorFree(IntPtr error);
    RtpPayloaderStats? ReadPayloaderStats(IntPtr pipeline, string name);
    PipelineDebugStats? ReadPipelineDebugStats(IntPtr pipeline) => null;
}

internal sealed class GStreamerApi : IGStreamerApi
{
    private IntPtr _clockIdentity;
    private int _clockGeneration;
    public void Init() => GStreamerNative.Init(IntPtr.Zero, IntPtr.Zero);
    public IntPtr ParseLaunch(string description, out IntPtr error) => GStreamerNative.ParseLaunch(description, out error);
    public GstStateChangeReturn SetState(IntPtr element, GstState state) => GStreamerNative.SetState(element, state);
    public GstStateChangeReturn GetState(IntPtr element, out GstState state, out GstState pending, ulong timeout) =>
        GStreamerNative.GetState(element, out state, out pending, timeout);
    public bool SeekSimple(IntPtr element, int format, int seekFlags, long position) =>
        GStreamerNative.SeekSimple(element, format, seekFlags, position);
    public bool QueryPosition(IntPtr element, int format, out long position) =>
        GStreamerNative.QueryPosition(element, format, out position);
    public bool QueryDuration(IntPtr element, int format, out long duration) =>
        GStreamerNative.QueryDuration(element, format, out duration);
    public IntPtr GetBus(IntPtr element) => GStreamerNative.GetBus(element);
    public IntPtr BusTimedPopFiltered(IntPtr bus, ulong timeout, ulong types) =>
        GStreamerNative.BusTimedPopFiltered(bus, timeout, types);
    public void MiniObjectUnref(IntPtr message) => GStreamerNative.MiniObjectUnref(message);
    public void ObjectUnref(IntPtr value) => GStreamerNative.ObjectUnref(value);
    public void ErrorFree(IntPtr error) => GStreamerNative.ErrorFree(error);
    public RtpPayloaderStats? ReadPayloaderStats(IntPtr pipeline, string name)
    {
        var element = GStreamerNative.BinGetByName(pipeline, name);
        if (element == IntPtr.Zero) return null;
        var value = new GValue();
        try
        {
            _ = GStreamerNative.ValueInit(ref value, GStreamerNative.StructureGetType());
            GStreamerNative.ObjectGetProperty(element, "stats", ref value);
            var structure = GStreamerNative.ValueGetBoxed(ref value);
            if (structure == IntPtr.Zero
                || !GStreamerNative.StructureGetUInt(structure, "timestamp", out var timestamp)
                || !GStreamerNative.StructureGetUInt64(structure, "running-time", out var runningTime)
                || !GStreamerNative.StructureGetUInt(structure, "seqnum", out var seqnum)
                || !GStreamerNative.StructureGetUInt(structure, "ssrc", out var ssrc)) return null;
            return new(timestamp, runningTime, seqnum, ssrc);
        }
        finally
        {
            if (value.Type != 0) GStreamerNative.ValueUnset(ref value);
            GStreamerNative.ObjectUnref(element);
        }
    }

    public PipelineDebugStats? ReadPipelineDebugStats(IntPtr pipeline)
    {
        var clock = GStreamerNative.ElementGetClock(pipeline);
        if (clock == IntPtr.Zero) return null;
        try
        {
            if (clock != _clockIdentity)
            {
                _clockIdentity = clock;
                _clockGeneration++;
            }
            var clockTime = GStreamerNative.ClockGetTime(clock);
            var baseTime = GStreamerNative.ElementGetBaseTime(pipeline);
            var startTime = GStreamerNative.ElementGetStartTime(pipeline);
            return new(
                _clockGeneration,
                clockTime,
                baseTime,
                startTime,
                clockTime >= baseTime ? clockTime - baseTime : 0,
                ReadQueueStats(pipeline, "source_queue"),
                ReadQueueStats(pipeline, "video_queue"),
                ReadQueueStats(pipeline, "audio_queue"));
        }
        finally
        {
            GStreamerNative.ObjectUnref(clock);
        }
    }

    private static QueueDebugStats? ReadQueueStats(IntPtr pipeline, string name)
    {
        var queue = GStreamerNative.BinGetByName(pipeline, name);
        if (queue == IntPtr.Zero) return null;
        try
        {
            return new(
                ReadUInt64(queue, "current-level-time"),
                ReadUInt(queue, "current-level-buffers"),
                ReadUInt(queue, "current-level-bytes"));
        }
        finally
        {
            GStreamerNative.ObjectUnref(queue);
        }
    }

    private static ulong ReadUInt64(IntPtr element, string property)
    {
        var value = new GValue();
        try
        {
            var type = GStreamerNative.TypeFromName("guint64");
            if (type == 0) return 0;
            _ = GStreamerNative.ValueInit(ref value, type);
            GStreamerNative.ObjectGetProperty(element, property, ref value);
            return GStreamerNative.ValueGetUInt64(ref value);
        }
        finally
        {
            if (value.Type != 0) GStreamerNative.ValueUnset(ref value);
        }
    }

    private static uint ReadUInt(IntPtr element, string property)
    {
        var value = new GValue();
        try
        {
            var type = GStreamerNative.TypeFromName("guint");
            if (type == 0) return 0;
            _ = GStreamerNative.ValueInit(ref value, type);
            GStreamerNative.ObjectGetProperty(element, property, ref value);
            return GStreamerNative.ValueGetUInt(ref value);
        }
        finally
        {
            if (value.Type != 0) GStreamerNative.ValueUnset(ref value);
        }
    }

}

internal static partial class GStreamerNative
{
    private const string GStreamer = "gstreamer-1.0-0.dll";
    private const string Glib = "glib-2.0-0.dll";

    [LibraryImport(GStreamer, EntryPoint = "gst_init")]
    internal static partial void Init(IntPtr argc, IntPtr argv);

    [LibraryImport(GStreamer, EntryPoint = "gst_parse_launch", StringMarshalling = StringMarshalling.Utf8)]
    internal static partial IntPtr ParseLaunch(string description, out IntPtr error);

    [LibraryImport(GStreamer, EntryPoint = "gst_element_set_state")]
    internal static partial GstStateChangeReturn SetState(IntPtr element, GstState state);

    [LibraryImport(GStreamer, EntryPoint = "gst_element_get_state")]
    internal static partial GstStateChangeReturn GetState(IntPtr element, out GstState state, out GstState pending, ulong timeout);

    [LibraryImport(GStreamer, EntryPoint = "gst_element_seek_simple")]
    [return: MarshalAs(UnmanagedType.Bool)]
    internal static partial bool SeekSimple(IntPtr element, int format, int seekFlags, long position);

    [LibraryImport(GStreamer, EntryPoint = "gst_element_query_position")]
    [return: MarshalAs(UnmanagedType.Bool)]
    internal static partial bool QueryPosition(IntPtr element, int format, out long position);

    [LibraryImport(GStreamer, EntryPoint = "gst_element_query_duration")]
    [return: MarshalAs(UnmanagedType.Bool)]
    internal static partial bool QueryDuration(IntPtr element, int format, out long duration);

    [LibraryImport(GStreamer, EntryPoint = "gst_element_get_bus")]
    internal static partial IntPtr GetBus(IntPtr element);

    [LibraryImport(GStreamer, EntryPoint = "gst_element_get_clock")]
    internal static partial IntPtr ElementGetClock(IntPtr element);

    [LibraryImport(GStreamer, EntryPoint = "gst_clock_get_time")]
    internal static partial ulong ClockGetTime(IntPtr clock);

    [LibraryImport(GStreamer, EntryPoint = "gst_element_get_base_time")]
    internal static partial ulong ElementGetBaseTime(IntPtr element);

    [LibraryImport(GStreamer, EntryPoint = "gst_element_get_start_time")]
    internal static partial ulong ElementGetStartTime(IntPtr element);

    [LibraryImport(GStreamer, EntryPoint = "gst_bin_get_by_name", StringMarshalling = StringMarshalling.Utf8)]
    internal static partial IntPtr BinGetByName(IntPtr bin, string name);

    [LibraryImport(GStreamer, EntryPoint = "gst_structure_get_type")]
    internal static partial nuint StructureGetType();

    [LibraryImport(GStreamer, EntryPoint = "gst_structure_get_uint", StringMarshalling = StringMarshalling.Utf8)]
    [return: MarshalAs(UnmanagedType.Bool)]
    internal static partial bool StructureGetUInt(IntPtr structure, string field, out uint value);

    [LibraryImport(GStreamer, EntryPoint = "gst_structure_get_uint64", StringMarshalling = StringMarshalling.Utf8)]
    [return: MarshalAs(UnmanagedType.Bool)]
    internal static partial bool StructureGetUInt64(IntPtr structure, string field, out ulong value);

    [LibraryImport(GStreamer, EntryPoint = "gst_bus_timed_pop_filtered")]
    internal static partial IntPtr BusTimedPopFiltered(IntPtr bus, ulong timeout, ulong types);

    [LibraryImport(GStreamer, EntryPoint = "gst_mini_object_unref")]
    internal static partial void MiniObjectUnref(IntPtr message);

    [LibraryImport(GStreamer, EntryPoint = "gst_object_unref")]
    internal static partial void ObjectUnref(IntPtr value);

    [LibraryImport(Glib, EntryPoint = "g_error_free")]
    internal static partial void ErrorFree(IntPtr error);

    [LibraryImport("gobject-2.0-0.dll", EntryPoint = "g_value_init")]
    internal static partial IntPtr ValueInit(ref GValue value, nuint type);

    [LibraryImport("gobject-2.0-0.dll", EntryPoint = "g_value_unset")]
    internal static partial void ValueUnset(ref GValue value);

    [LibraryImport("gobject-2.0-0.dll", EntryPoint = "g_value_get_boxed")]
    internal static partial IntPtr ValueGetBoxed(ref GValue value);

    [LibraryImport("gobject-2.0-0.dll", EntryPoint = "g_type_from_name", StringMarshalling = StringMarshalling.Utf8)]
    internal static partial nuint TypeFromName(string name);

    [LibraryImport("gobject-2.0-0.dll", EntryPoint = "g_value_get_uint")]
    internal static partial uint ValueGetUInt(ref GValue value);


    [LibraryImport("gobject-2.0-0.dll", EntryPoint = "g_value_get_uint64")]
    internal static partial ulong ValueGetUInt64(ref GValue value);


    [LibraryImport("gobject-2.0-0.dll", EntryPoint = "g_object_get_property", StringMarshalling = StringMarshalling.Utf8)]
    internal static partial void ObjectGetProperty(IntPtr value, string property, ref GValue output);


}
