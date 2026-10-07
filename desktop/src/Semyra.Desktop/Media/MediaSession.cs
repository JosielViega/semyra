namespace Semyra.Desktop.Media;

// A session is deliberately represented by the MediaEngine cancellation/task pair.
// This marker keeps the lifecycle concept explicit without duplicating state ownership.
internal static class MediaSession
{
    internal const int MaximumReconnects = 3;
}
