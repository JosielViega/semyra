using System.IO;

namespace Semyra.Desktop.Iptv;

public static class IptvPaths
{
    public static string DatabasePath()
    {
        var local = Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData);
        if (string.IsNullOrWhiteSpace(local))
        {
            throw new InvalidOperationException("O diretório de dados locais não está disponível.");
        }

        var directory = Path.Combine(local, "Semyra", "Data");
        Directory.CreateDirectory(directory);
        return Path.Combine(directory, "semyra.db");
    }
}
