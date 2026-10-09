using System.IO;
using System.Runtime.CompilerServices;
using System.Text;
using System.Text.RegularExpressions;

namespace Semyra.Desktop.Iptv;

public sealed partial class M3uParser
{
    public async IAsyncEnumerable<ParsedM3uChannel> ParseAsync(
        Stream stream,
        [EnumeratorCancellation] CancellationToken cancellationToken = default)
    {
        using var reader = new StreamReader(stream, new UTF8Encoding(false, true), true, 16 * 1024, leaveOpen: true);
        string? pendingExtInf = null;

        while (await reader.ReadLineAsync(cancellationToken).ConfigureAwait(false) is { } rawLine)
        {
            var line = rawLine.Trim().TrimStart('\uFEFF');
            if (line.Length == 0)
            {
                continue;
            }

            if (line.StartsWith("#EXTINF:", StringComparison.OrdinalIgnoreCase))
            {
                pendingExtInf = line;
                continue;
            }

            if (line.StartsWith('#') || pendingExtInf is null)
            {
                continue;
            }

            var parsed = ParseEntry(pendingExtInf, line);
            pendingExtInf = null;
            if (parsed is not null)
            {
                yield return parsed;
            }
        }
    }

    private static ParsedM3uChannel? ParseEntry(string extInf, string streamUrl)
    {
        if (!Uri.TryCreate(streamUrl, UriKind.Absolute, out var uri)
            || uri.Scheme is "file" or "javascript" or "data")
        {
            return null;
        }

        var comma = extInf.LastIndexOf(',');
        if (comma < 0 || comma == extInf.Length - 1)
        {
            return null;
        }

        var attributes = extInf[..comma];
        var name = Clean(extInf[(comma + 1)..], 240);
        if (name is null)
        {
            return null;
        }

        string? Attribute(string key)
        {
            foreach (Match match in AttributePattern().Matches(attributes))
            {
                if (string.Equals(match.Groups[1].Value, key, StringComparison.OrdinalIgnoreCase))
                {
                    return Clean(match.Groups[2].Value, 1024);
                }
            }
            return null;
        }

        var tvgName = Attribute("tvg-name");
        return new ParsedM3uChannel(
            tvgName ?? name,
            uri.AbsoluteUri,
            Attribute("tvg-id"),
            Attribute("tvg-logo"),
            Attribute("group-title"));
    }

    private static string? Clean(string value, int maxLength)
    {
        var clean = value.Trim();
        return clean.Length is 0 or > 4096 ? null : clean[..Math.Min(clean.Length, maxLength)];
    }

    [GeneratedRegex("(?:^|\\s)([A-Za-z0-9_-]+)=\"([^\"]*)\"", RegexOptions.CultureInvariant)]
    private static partial Regex AttributePattern();
}
