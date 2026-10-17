using System.Net;
using System.Net.Http;
using System.Text;
using Semyra.Desktop.Iptv;

namespace Semyra.Desktop.Tests;

public sealed class XtreamClientTests
{
    private static readonly XtreamSourceConfiguration Configuration =
        new(1, "https://provider.example:8443", "test-user", "test-pass");

    [Theory]
    [InlineData("1")]
    [InlineData("\"1\"")]
    public async Task AuthenticationAcceptsOnlyNumericOrStringOne(string auth)
    {
        using var http = new HttpClient(new FakeXtreamServerHandler(_ => Json("{\"user_info\":{\"auth\":" + auth + "}}")));
        var client = new XtreamClient(http);
        await client.AuthenticateAsync(Configuration, default);
    }

    [Theory]
    [InlineData("0")]
    [InlineData("\"0\"")]
    [InlineData("true")]
    [InlineData("null")]
    public async Task AuthenticationRejectsOtherRepresentations(string auth)
    {
        using var http = new HttpClient(new FakeXtreamServerHandler(_ => Json("{\"user_info\":{\"auth\":" + auth + "}}")));
        var error = await Assert.ThrowsAsync<XtreamException>(() => new XtreamClient(http).AuthenticateAsync(Configuration, default));
        Assert.Equal("xtream_auth_failed", error.Code);
    }

    [Theory]
    [InlineData(HttpStatusCode.Unauthorized, "xtream_auth_failed")]
    [InlineData(HttpStatusCode.Forbidden, "xtream_auth_failed")]
    [InlineData(HttpStatusCode.InternalServerError, "xtream_connect_failed")]
    public async Task HttpFailuresUseSafeCodes(HttpStatusCode status, string code)
    {
        using var http = new HttpClient(new FakeXtreamServerHandler(_ => new HttpResponseMessage(status)));
        var error = await Assert.ThrowsAsync<XtreamException>(() => new XtreamClient(http).AuthenticateAsync(Configuration, default));
        Assert.Equal(code, error.Code);
        Assert.DoesNotContain(Configuration.Username, error.ToString(), StringComparison.Ordinal);
        Assert.DoesNotContain(Configuration.Password, error.ToString(), StringComparison.Ordinal);
    }

    [Theory]
    [InlineData("")]
    [InlineData("not-json")]
    [InlineData("{}")]
    public async Task InvalidPayloadsAreRejected(string payload)
    {
        using var http = new HttpClient(new FakeXtreamServerHandler(_ => Json(payload)));
        var error = await Assert.ThrowsAsync<XtreamException>(() => new XtreamClient(http).AuthenticateAsync(Configuration, default));
        Assert.Contains(error.Code, new[] { "xtream_invalid_response", "xtream_auth_failed" });
    }

    [Fact]
    public async Task TimeoutIsBoundedAndReportedSafely()
    {
        using var http = new HttpClient(new FakeXtreamServerHandler(async (_, token) =>
        {
            await Task.Delay(TimeSpan.FromSeconds(1), token);
            return Json("{\"user_info\":{\"auth\":1}}");
        }));
        var error = await Assert.ThrowsAsync<XtreamException>(() =>
            new XtreamClient(http, TimeSpan.FromMilliseconds(10)).AuthenticateAsync(Configuration, default));
        Assert.Equal("xtream_timeout", error.Code);
    }

    [Fact]
    public async Task CategoriesAndStreamsNormalizeProviderTypesAndKeepFirstDuplicate()
    {
        using var http = new HttpClient(new FakeXtreamServerHandler(request =>
        {
            var query = request.RequestUri!.Query;
            if (query.Contains("get_live_categories", StringComparison.Ordinal))
            {
                return Json("""[{"category_id":7,"category_name":"News"},{"category_id":"7","category_name":"Duplicate"},{"category_id":null,"category_name":"Invalid"}]""");
            }
            return Json("""[{"stream_id":100,"name":"Channel A","category_id":7,"stream_icon":"https://img.example/a.png","epg_channel_id":"epg-a","direct_source":"https://must-not-be-used.invalid"},{"stream_id":"100","name":"Duplicate"},{"stream_id":"101","name":"Channel B","category_id":"unknown"},{"stream_id":null,"name":"Invalid"},{"stream_id":"102"}]""");
        }));
        var client = new XtreamClient(http);
        var categories = await client.GetLiveCategoriesAsync(Configuration, default);
        var result = await client.GetLiveStreamsAsync(Configuration, categories.ToDictionary(x => x.Id, x => x.Name), default);

        Assert.Equal("News", Assert.Single(categories).Name);
        Assert.Equal(5, result.Received);
        Assert.Equal(3, result.Skipped);
        Assert.Equal(2, result.Channels.Count);
        var first = result.Channels[0];
        Assert.Equal("100", first.ExternalId);
        Assert.Equal("xtream:live:100", first.StableIdentity);
        Assert.Equal("News", first.Group);
        Assert.Equal("https://provider.example:8443/live/test-user/test-pass/100.ts", first.StreamUrl);
        Assert.DoesNotContain("must-not-be-used", first.StreamUrl, StringComparison.Ordinal);
    }

    [Fact]
    public async Task RedirectResponseIsNotFollowedByTheClient()
    {
        using var http = new HttpClient(new FakeXtreamServerHandler(_ => new HttpResponseMessage(HttpStatusCode.Redirect)
        {
            Headers = { Location = new Uri("https://other.example/player_api.php") },
        }));
        var error = await Assert.ThrowsAsync<XtreamException>(() => new XtreamClient(http).AuthenticateAsync(Configuration, default));
        Assert.Equal("xtream_connect_failed", error.Code);
    }

    private static HttpResponseMessage Json(string value) => new(HttpStatusCode.OK)
    {
        Content = new StringContent(value, Encoding.UTF8, "application/json"),
    };

    private sealed class FakeXtreamServerHandler : HttpMessageHandler
    {
        private readonly Func<HttpRequestMessage, CancellationToken, Task<HttpResponseMessage>> _response;

        public FakeXtreamServerHandler(Func<HttpRequestMessage, HttpResponseMessage> response)
            : this((request, _) => Task.FromResult(response(request))) { }

        public FakeXtreamServerHandler(Func<HttpRequestMessage, CancellationToken, Task<HttpResponseMessage>> response)
        {
            _response = response;
        }

        protected override Task<HttpResponseMessage> SendAsync(HttpRequestMessage request, CancellationToken cancellationToken) =>
            _response(request, cancellationToken);
    }
}
