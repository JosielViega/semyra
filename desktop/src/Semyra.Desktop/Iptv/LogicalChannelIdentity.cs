using System.Security.Cryptography;
using System.Text;

namespace Semyra.Desktop.Iptv;

internal static class LogicalChannelIdentity
{
    public static string Create(string? tvgId, string name, string? group)
    {
        var normalizedTvgId = Normalize(tvgId);
        var identity = normalizedTvgId.Length > 0
            ? $"tvg:{normalizedTvgId}"
            : $"name-group:{Normalize(name)}\n{Normalize(group)}";
        return Convert.ToHexString(SHA256.HashData(Encoding.UTF8.GetBytes(identity))).ToLowerInvariant();
    }

    internal static string Normalize(string? value)
    {
        var input = (value ?? string.Empty).Normalize(NormalizationForm.FormKC).Trim();
        if (input.Length == 0)
        {
            return string.Empty;
        }

        var result = new StringBuilder(input.Length);
        var pendingSpace = false;
        foreach (var character in input)
        {
            if (char.IsWhiteSpace(character))
            {
                pendingSpace = result.Length > 0;
                continue;
            }
            if (pendingSpace)
            {
                result.Append(' ');
                pendingSpace = false;
            }
            result.Append(char.ToUpperInvariant(character));
        }
        return result.ToString();
    }
}
