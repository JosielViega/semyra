using System.IO;

namespace Semyra.Desktop.Media;

public static class MpegTsValidator
{
    private const int PacketSize = 188;
    private const int RequiredPackets = 3;
    private const int ProbeSize = 32 * 1024;

    public static async Task<byte[]> ReadAndValidateAsync(Stream stream, CancellationToken cancellationToken)
    {
        var buffer = new byte[ProbeSize];
        var length = 0;
        while (length < PacketSize * (RequiredPackets + 1))
        {
            var read = await stream.ReadAsync(buffer.AsMemory(length, buffer.Length - length), cancellationToken);
            if (read == 0)
            {
                break;
            }
            length += read;
        }

        if (!HasSync(buffer.AsSpan(0, length)))
        {
            throw new MediaEngineException("invalid_mpegts");
        }

        return buffer.AsSpan(0, length).ToArray();
    }

    internal static bool HasSync(ReadOnlySpan<byte> buffer)
    {
        if (buffer.Length < PacketSize * RequiredPackets)
        {
            return false;
        }
        var maximumOffset = Math.Min(PacketSize - 1, buffer.Length - (PacketSize * RequiredPackets));
        for (var offset = 0; offset <= maximumOffset; offset++)
        {
            if (buffer[offset] == 0x47
                && buffer[offset + PacketSize] == 0x47
                && buffer[offset + (PacketSize * 2)] == 0x47)
            {
                return true;
            }
        }
        return false;
    }
}
