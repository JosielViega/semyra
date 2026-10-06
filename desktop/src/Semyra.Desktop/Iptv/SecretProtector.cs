using System.Security.Cryptography;
using System.Text;

namespace Semyra.Desktop.Iptv;

public interface ISecretProtector
{
    byte[] Protect(string value);
    string Unprotect(byte[] value);
}

public sealed class DpapiSecretProtector : ISecretProtector
{
    private static readonly byte[] Entropy = Encoding.UTF8.GetBytes("Semyra.IPTV.v1");

    public byte[] Protect(string value)
    {
        return ProtectedData.Protect(
            Encoding.UTF8.GetBytes(value),
            Entropy,
            DataProtectionScope.CurrentUser);
    }

    public string Unprotect(byte[] value)
    {
        return Encoding.UTF8.GetString(ProtectedData.Unprotect(
            value,
            Entropy,
            DataProtectionScope.CurrentUser));
    }
}
