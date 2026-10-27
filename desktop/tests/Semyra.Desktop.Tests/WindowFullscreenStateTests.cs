using System.Windows;
using Semyra.Desktop.Desktop;

namespace Semyra.Desktop.Tests;

public sealed class WindowFullscreenStateTests
{
    [Fact]
    public void WindowedStateRestoresExactBounds()
    {
        var state = new WindowFullscreenState();
        var bounds = new Rect(120, 80, 1280, 720);

        Assert.True(state.Enter(WindowStyle.SingleBorderWindow, ResizeMode.CanResize, WindowState.Normal, bounds));
        var restored = state.Exit();

        Assert.NotNull(restored);
        Assert.Equal(WindowState.Normal, restored.WindowState);
        Assert.Equal(bounds, restored.Bounds);
        Assert.Equal(WindowStyle.SingleBorderWindow, restored.Style);
        Assert.Equal(ResizeMode.CanResize, restored.ResizeMode);
    }

    [Fact]
    public void MaximizedStateReturnsToMaximized()
    {
        var state = new WindowFullscreenState();
        Assert.True(state.Enter(
            WindowStyle.SingleBorderWindow,
            ResizeMode.CanResize,
            WindowState.Maximized,
            new Rect(20, 20, 1000, 700)));

        Assert.Equal(WindowState.Maximized, state.Exit()!.WindowState);
    }

    [Fact]
    public void EnterAndExitAreIdempotent()
    {
        var state = new WindowFullscreenState();
        Assert.True(state.Enter(WindowStyle.SingleBorderWindow, ResizeMode.CanResize, WindowState.Normal, new Rect(0, 0, 800, 600)));
        Assert.False(state.Enter(WindowStyle.None, ResizeMode.NoResize, WindowState.Normal, Rect.Empty));
        Assert.NotNull(state.Exit());
        Assert.Null(state.Exit());
    }
}
