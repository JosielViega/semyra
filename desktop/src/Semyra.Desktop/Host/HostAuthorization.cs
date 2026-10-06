namespace Semyra.Desktop.Host;

public sealed record HostAuthorization(
    string Token,
    string RoomCode,
    string TransmissionInstanceId,
    long TransmissionRevision,
    string Permission,
    DateTimeOffset ExpiresAt);
