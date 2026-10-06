using Semyra.Desktop.Host;

namespace Semyra.Desktop.Desktop;

public sealed record DesktopRequest(
    string Type,
    string RequestId,
    HostAuthorization? Authorization = null);
