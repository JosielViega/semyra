using Semyra.Desktop.Desktop;

namespace Semyra.Desktop.Tests;

public sealed class NavigationPolicyTests
{
    [Fact]
    public void AllowsSameOriginAndRejectsExternalOrigin()
    {
        var policy = new NavigationPolicy(new Uri("https://semyra.example/room/ABC"));

        Assert.True(policy.IsAllowed(new Uri("https://semyra.example/login?next=room")));
        Assert.False(policy.IsAllowed(new Uri("https://external.example/")));
        Assert.False(policy.IsAllowed(new Uri("https://semyra.example:8443/")));
    }

    [Theory]
    [InlineData("http://localhost:8010/")]
    [InlineData("http://127.0.0.1:8010/room/ABC")]
    public void AllowsLocalHttpDuringDevelopment(string value)
    {
        Assert.True(NavigationPolicy.TryCreateBaseUri(value, allowLocalHttp: true, out var uri));
        Assert.NotNull(uri);
    }

    [Theory]
    [InlineData("http://example.com/")]
    [InlineData("http://192.168.0.10:8010/")]
    [InlineData("http://localhost:8010/")]
    public void RejectsHttpWhenNotPermitted(string value)
    {
        Assert.False(NavigationPolicy.TryCreateBaseUri(value, allowLocalHttp: false, out _));
    }

    [Theory]
    [InlineData("not a URL")]
    [InlineData("javascript:alert(1)")]
    [InlineData("https://" + "user:password" + "@example.com/")]
    [InlineData("")]
    public void RejectsMalformedOrUnsafeUrl(string value)
    {
        Assert.False(NavigationPolicy.TryCreateBaseUri(value, allowLocalHttp: true, out _));
    }
}
