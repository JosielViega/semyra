using System.IO;

namespace Semyra.Desktop.Iptv;

public static class IptvPaths
{
    public static string DatabasePath(string profileId)
    {
        if (profileId.Length != 64 || profileId.Any(character => character is not (>= 'a' and <= 'f') and not (>= '0' and <= '9')))
        {
            throw new ArgumentException("Perfil local inválido.", nameof(profileId));
        }
        var local = Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData);
        if (string.IsNullOrWhiteSpace(local))
        {
            throw new InvalidOperationException("O diretório de dados locais não está disponível.");
        }

        return DatabasePath(profileId, local);
    }

    internal static string DatabasePath(string profileId, string localRoot)
    {
        if (profileId.Length != 64 || profileId.Any(character => character is not (>= 'a' and <= 'f') and not (>= '0' and <= '9')))
        {
            throw new ArgumentException("Perfil local inválido.", nameof(profileId));
        }
        var directory = Path.Combine(localRoot, "Semyra", "Data", "Profiles", profileId);
        Directory.CreateDirectory(directory);
        return Path.Combine(directory, "semyra.db");
    }
}
