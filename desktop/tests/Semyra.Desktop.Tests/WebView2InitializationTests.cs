using System.Runtime.InteropServices;
using Semyra.Desktop.Desktop;

namespace Semyra.Desktop.Tests;

public sealed class WebView2InitializationTests
{
    [Fact]
    public async Task RetriesInvalidStateExactlyOnceAfterDelay()
    {
        var attempts = 0;
        var delays = 0;

        await WebView2Initialization.RunWithInvalidStateRetryAsync(
            () =>
            {
                attempts++;
                if (attempts == 1)
                    throw new COMException("transient", unchecked((int)0x8007139F));
                return Task.CompletedTask;
            },
            () =>
            {
                delays++;
                return Task.CompletedTask;
            });

        Assert.Equal(2, attempts);
        Assert.Equal(1, delays);
    }

    [Fact]
    public async Task DoesNotRetryUnrelatedComFailure()
    {
        var attempts = 0;

        var exception = await Assert.ThrowsAsync<COMException>(() =>
            WebView2Initialization.RunWithInvalidStateRetryAsync(
                () =>
                {
                    attempts++;
                    throw new COMException("other", unchecked((int)0x80004005));
                },
                () => Task.CompletedTask));

        Assert.Equal(unchecked((int)0x80004005), exception.HResult);
        Assert.Equal(1, attempts);
    }

    [Fact]
    public async Task PropagatesSecondInvalidStateFailure()
    {
        var attempts = 0;

        await Assert.ThrowsAsync<COMException>(() =>
            WebView2Initialization.RunWithInvalidStateRetryAsync(
                () =>
                {
                    attempts++;
                    throw new COMException("persistent", unchecked((int)0x8007139F));
                },
                () => Task.CompletedTask));

        Assert.Equal(2, attempts);
    }
}
