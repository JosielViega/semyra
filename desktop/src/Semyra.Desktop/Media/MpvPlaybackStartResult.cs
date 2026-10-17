namespace Semyra.Desktop.Media;

public sealed record MpvPlaybackStartResult(
    MediaSnapshot Snapshot,
    string PlaybackKind,
    string SessionId);
