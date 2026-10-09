using System.IO;
using System.Net;
using System.Net.Http;
using System.Net.Http.Headers;
using System.Security.Cryptography;

namespace Semyra.Desktop.Iptv;

public sealed class IptvCatalogService : IDisposable
{
    private readonly IptvStore _store;
    private readonly ISecretProtector _protector;
    private readonly M3uParser _parser;
    private readonly HttpClient _httpClient;
    private readonly bool _ownsHttpClient;

    public IptvCatalogService(
        IptvStore store,
        ISecretProtector protector,
        M3uParser parser,
        HttpClient? httpClient = null)
    {
        _store = store;
        _protector = protector;
        _parser = parser;
        _ownsHttpClient = httpClient is null;
        _httpClient = httpClient ?? new HttpClient(new SocketsHttpHandler
        {
            AutomaticDecompression = DecompressionMethods.All,
            AllowAutoRedirect = true,
        }) { Timeout = TimeSpan.FromSeconds(45) };
        _httpClient.DefaultRequestHeaders.UserAgent.Add(new ProductInfoHeaderValue("SemyraDesktop", "1.0"));
    }

    public static IptvCatalogService CreateDefault(string profileId)
    {
        var protector = new DpapiSecretProtector();
        return new IptvCatalogService(
            new IptvStore(IptvPaths.DatabasePath(profileId), protector),
            protector,
            new M3uParser());
    }

    public void Initialize() => _store.Initialize();

    public IReadOnlyList<IptvSourceSummary> ListSources() => _store.ListSources();

    public IptvSourceSummary AddUrlSource(string name, string location)
    {
        var safeName = ValidateName(name);
        if (!Uri.TryCreate(location, UriKind.Absolute, out var uri)
            || uri.Scheme is not ("http" or "https"))
        {
            throw new IptvValidationException("Informe uma URL HTTP ou HTTPS válida.");
        }
        return _store.AddSource(safeName, IptvSourceType.M3uUrl, uri.AbsoluteUri);
    }

    public IptvSourceSummary AddFileSource(string path)
    {
        if (string.IsNullOrWhiteSpace(path) || !File.Exists(path))
        {
            throw new IptvValidationException("O arquivo selecionado não está disponível.");
        }
        var extension = Path.GetExtension(path);
        if (!string.Equals(extension, ".m3u", StringComparison.OrdinalIgnoreCase)
            && !string.Equals(extension, ".m3u8", StringComparison.OrdinalIgnoreCase))
        {
            throw new IptvValidationException("Selecione um arquivo M3U ou M3U8.");
        }
        var name = ValidateName(Path.GetFileNameWithoutExtension(path));
        return _store.AddSource(name, IptvSourceType.M3uFile, Path.GetFullPath(path));
    }

    public bool RemoveSource(long sourceId) => _store.RemoveSource(sourceId);

    internal IptvPlaybackChannel ResolveChannelForPlayback(long channelId)
    {
        return _store.ResolveChannelForPlayback(PositiveId(channelId));
    }

    public IReadOnlyList<string> GetGroups(long sourceId) => _store.GetGroups(PositiveId(sourceId));

    public IptvChannelSearchResult SearchChannels(
        long sourceId,
        string? query,
        string? group,
        int offset,
        int limit)
    {
        if (offset < 0 || limit is < 1 or > 100)
        {
            throw new IptvValidationException("Paginação inválida.");
        }
        var safeQuery = (query ?? string.Empty).Trim();
        var safeGroup = string.IsNullOrWhiteSpace(group) ? null : group.Trim();
        if (safeQuery.Length > 120 || safeGroup?.Length > 240)
        {
            throw new IptvValidationException("Filtro inválido.");
        }
        return _store.SearchChannels(PositiveId(sourceId), safeQuery, safeGroup, offset, limit);
    }

    public async Task<IptvSourceSummary> RefreshAsync(long sourceId, CancellationToken cancellationToken)
    {
        var source = _store.FindSource(PositiveId(sourceId))
            ?? throw new IptvValidationException("Fonte não encontrada.");
        _store.MarkRefreshing(sourceId);

        try
        {
            var location = _protector.Unprotect(source.ProtectedLocation);
            await using var stream = source.Type switch
            {
                IptvSourceType.M3uUrl => await OpenUrlAsync(location, cancellationToken).ConfigureAwait(false),
                IptvSourceType.M3uFile => OpenFile(location),
                _ => throw new IptvValidationException("Tipo de fonte não suportado."),
            };
            return await _store.ReplaceChannelsAsync(
                sourceId,
                _parser.ParseAsync(stream, cancellationToken),
                cancellationToken).ConfigureAwait(false);
        }
        catch (OperationCanceledException)
        {
            _store.MarkRefreshError(sourceId, "Atualização cancelada.");
            throw;
        }
        catch (Exception exception) when (exception is HttpRequestException or IOException or InvalidDataException or CryptographicException)
        {
            var safeError = exception switch
            {
                FileNotFoundException => "O arquivo selecionado não está mais disponível.",
                InvalidDataException => "A playlist não contém canais válidos.",
                _ => "Não foi possível atualizar a fonte.",
            };
            _store.MarkRefreshError(sourceId, safeError);
            throw new IptvRefreshException(safeError, exception);
        }
    }

    public void Dispose()
    {
        if (_ownsHttpClient)
        {
            _httpClient.Dispose();
        }
    }

    private async Task<Stream> OpenUrlAsync(string location, CancellationToken cancellationToken)
    {
        using var request = new HttpRequestMessage(HttpMethod.Get, location);
        var response = await _httpClient.SendAsync(request, HttpCompletionOption.ResponseHeadersRead, cancellationToken).ConfigureAwait(false);
        try
        {
            response.EnsureSuccessStatusCode();
            var source = await response.Content.ReadAsStreamAsync(cancellationToken).ConfigureAwait(false);
            return new ResponseOwnedStream(source, response);
        }
        catch
        {
            response.Dispose();
            throw;
        }
    }

    private static FileStream OpenFile(string location)
    {
        return new FileStream(location, FileMode.Open, FileAccess.Read, FileShare.Read, 64 * 1024, FileOptions.Asynchronous | FileOptions.SequentialScan);
    }

    private static string ValidateName(string name)
    {
        var value = name.Trim();
        if (value.Length is < 1 or > 100)
        {
            throw new IptvValidationException("O nome da fonte deve ter entre 1 e 100 caracteres.");
        }
        return value;
    }

    private static long PositiveId(long sourceId)
    {
        return sourceId > 0 ? sourceId : throw new IptvValidationException("Fonte inválida.");
    }

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
        public override Task<int> ReadAsync(byte[] buffer, int offset, int count, CancellationToken cancellationToken) => inner.ReadAsync(buffer, offset, count, cancellationToken);
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

public sealed class IptvValidationException(string message) : Exception(message);

public sealed class IptvRefreshException(string message, Exception innerException) : Exception(message, innerException);
