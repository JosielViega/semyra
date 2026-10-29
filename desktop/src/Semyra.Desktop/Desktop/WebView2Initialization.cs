using System.Runtime.InteropServices;

namespace Semyra.Desktop.Desktop;

internal static class WebView2Initialization
{
    private const int InvalidStateHResult = unchecked((int)0x8007139F);

    internal static async Task RunWithInvalidStateRetryAsync(
        Func<Task> initialize,
        Func<Task>? delay = null)
    {
        ArgumentNullException.ThrowIfNull(initialize);
        delay ??= () => Task.Delay(TimeSpan.FromMilliseconds(400));

        try
        {
            await initialize();
        }
        catch (COMException exception) when (exception.HResult == InvalidStateHResult)
        {
            await delay();
            await initialize();
        }
    }
}
