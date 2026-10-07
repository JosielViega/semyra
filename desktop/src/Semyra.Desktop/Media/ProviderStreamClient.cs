using System.IO;
using System.Net;
using System.Net.Http;
using System.Net.Http.Headers;

namespace Semyra.Desktop.Media;

internal interface IProviderStreamClient : IDisposable
{
    Task<Stream> OpenAsync(Uri streamUri, CancellationToken cancellationToken);
}

internal sealed class ProviderStreamClient : IProviderStreamClient
{
    private readonly HttpClient _client;

    public ProviderStreamClient()
    {
        _client = new HttpClient(new SocketsHttpHandler
        {
            AllowAutoRedirect = true,
            AutomaticDecompression = DecompressionMethods.None,
            ConnectTimeout = TimeSpan.FromSeconds(20),
        })
        {
            Timeout = Timeout.InfiniteTimeSpan,
        };
        _client.DefaultRequestHeaders.UserAgent.Add(new ProductInfoHeaderValue("SemyraDesktop", "1.0"));
    }

    public async Task<Stream> OpenAsync(Uri streamUri, CancellationToken cancellationToken)
    {
        using var preparationTimeout = CancellationTokenSource.CreateLinkedTokenSource(cancellationToken);
        preparationTimeout.CancelAfter(TimeSpan.FromSeconds(30));
        using var request = new HttpRequestMessage(HttpMethod.Get, streamUri);
        HttpResponseMessage response;
        try
        {
            response = await _client.SendAsync(request, HttpCompletionOption.ResponseHeadersRead, preparationTimeout.Token);
            response.EnsureSuccessStatusCode();
        }
        catch (OperationCanceledException) when (!cancellationToken.IsCancellationRequested)
        {
            throw new MediaEngineException("provider_connect_failed");
        }
        catch (HttpRequestException exception)
        {
            throw new MediaEngineException("provider_connect_failed", exception);
        }

        try
        {
            var stream = await response.Content.ReadAsStreamAsync(cancellationToken);
            return new ResponseOwnedStream(stream, response);
        }
        catch
        {
            response.Dispose();
            throw;
        }
    }

    public void Dispose() => _client.Dispose();

    private sealed class ResponseOwnedStream(Stream inner, HttpResponseMessage response) : Stream
    {
        public override bool CanRead => inner.CanRead;
        public override bool CanSeek => inner.CanSeek;
        public override bool CanWrite => false;
        public override long Length => inner.Length;
        public override long Position { get => inner.Position; set => inner.Position = value; }
        public override void Flush() => inner.Flush();
        public override int Read(byte[] buffer, int offset, int count) => inner.Read(buffer, offset, count);
        public override long Seek(long offset, SeekOrigin origin) => inner.Seek(offset, origin);
        public override void SetLength(long value) => throw new NotSupportedException();
        public override void Write(byte[] buffer, int offset, int count) => throw new NotSupportedException();
        public override ValueTask<int> ReadAsync(Memory<byte> buffer, CancellationToken cancellationToken = default) => inner.ReadAsync(buffer, cancellationToken);
        protected override void Dispose(bool disposing)
        {
            if (disposing)
            {
                inner.Dispose();
                response.Dispose();
            }
            base.Dispose(disposing);
        }
        public override async ValueTask DisposeAsync()
        {
            await inner.DisposeAsync();
            response.Dispose();
            GC.SuppressFinalize(this);
        }
    }
}
