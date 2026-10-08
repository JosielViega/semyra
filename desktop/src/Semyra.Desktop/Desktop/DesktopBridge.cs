using System.Reflection;
using System.Text.Json;
using Semyra.Desktop.Host;
using Semyra.Desktop.Iptv;
using Semyra.Desktop.Media;

namespace Semyra.Desktop.Desktop;

public static class DesktopBridge
{
    private static readonly JsonSerializerOptions JsonOptions = new(JsonSerializerDefaults.Web);

    public static bool TryHandle(string json, HostEngine hostEngine, out string response)
    {
        response = string.Empty;
        if (!DesktopMessage.TryReadRequest(json, out var request))
        {
            return false;
        }

        response = request.Type switch
        {
            DesktopMessage.PingType => CreatePong(request.RequestId),
            DesktopMessage.HostStatusType => CreateHostStatus(request.RequestId, hostEngine.Snapshot()),
            DesktopMessage.HostAuthorizeType => CreateHostAuthorizeResult(
                request.RequestId,
                request.Authorization!,
                hostEngine.Authorize(request.Authorization!)),
            DesktopMessage.HostClearType => CreateHostClearResult(request.RequestId, hostEngine),
            DesktopMessage.AccountActivateType => CreateAccountActivateResult(request.RequestId, hostEngine, request.Account!.ProfileId!),
            DesktopMessage.AccountClearType => CreateAccountClearResult(request.RequestId, hostEngine),
            _ => string.Empty,
        };

        return response.Length > 0;
    }

    public static async Task<(bool Handled, string Response)> TryHandleAsync(
        string json,
        HostEngine hostEngine,
        Func<string?> pickM3uFile,
        CancellationToken cancellationToken = default)
    {
        if (!DesktopMessage.TryReadRequest(json, out var request))
        {
            return (false, string.Empty);
        }

        if (request.Iptv is null)
        {
            if (request.Media is not null)
            {
                return await HandleMediaAsync(request, hostEngine, cancellationToken);
            }
            return TryHandle(json, hostEngine, out var response)
                ? (true, response)
                : (false, string.Empty);
        }

        if (!hostEngine.IsCurrentAccountContext(request.Iptv.AccountContextId))
        {
            return (true, Error(ResultType(request.Type), request.RequestId, "account_context_changed"));
        }

        try
        {
            return (true, request.Type switch
            {
                DesktopMessage.IptvSourcesListType => Success(
                    DesktopMessage.IptvSourcesListResultType,
                    request.RequestId,
                    new { sources = hostEngine.ListIptvSources() }),
                DesktopMessage.IptvSourcesAddType => Success(
                    DesktopMessage.IptvSourcesAddResultType,
                    request.RequestId,
                    new { source = hostEngine.AddIptvUrl(request.Iptv.Name!, request.Iptv.Location!) }),
                DesktopMessage.IptvSourcesRemoveType => Success(
                    DesktopMessage.IptvSourcesRemoveResultType,
                    request.RequestId,
                    new { removed = hostEngine.RemoveIptvSource(request.Iptv.SourceId!.Value) }),
                DesktopMessage.IptvSourcesRefreshType => Success(
                    DesktopMessage.IptvSourcesRefreshResultType,
                    request.RequestId,
                    new { source = await hostEngine.RefreshIptvSourceAsync(request.Iptv.SourceId!.Value, cancellationToken) }),
                DesktopMessage.IptvSourcesPickFileType => PickFile(request.RequestId, hostEngine, pickM3uFile),
                DesktopMessage.IptvCatalogGroupsType => Success(
                    DesktopMessage.IptvCatalogGroupsResultType,
                    request.RequestId,
                    new { groups = hostEngine.GetIptvGroups(request.Iptv.SourceId!.Value) }),
                DesktopMessage.IptvCatalogSearchType => Success(
                    DesktopMessage.IptvCatalogSearchResultType,
                    request.RequestId,
                    hostEngine.SearchIptvChannels(
                        request.Iptv.SourceId!.Value,
                        request.Iptv.Query,
                        request.Iptv.Group,
                        request.Iptv.Offset,
                        request.Iptv.Limit)),
                _ => throw new InvalidOperationException("Comando IPTV não reconhecido."),
            });
        }
        catch (OperationCanceledException)
        {
            return (true, Error(ResultType(request.Type), request.RequestId, "Operação cancelada."));
        }
        catch (Exception exception) when (exception is IptvValidationException or IptvRefreshException)
        {
            return (true, Error(ResultType(request.Type), request.RequestId, exception.Message));
        }
        catch
        {
            return (true, Error(ResultType(request.Type), request.RequestId, "Não foi possível concluir a operação local."));
        }
    }

    private static async Task<(bool Handled, string Response)> HandleMediaAsync(
        DesktopRequest request,
        HostEngine hostEngine,
        CancellationToken cancellationToken)
    {
        if (!hostEngine.IsCurrentAccountContext(request.Media!.AccountContextId))
        {
            return (true, MediaError(ResultType(request.Type), request.RequestId, "account_context_changed"));
        }
        try
        {
            var snapshot = request.Type switch
            {
                DesktopMessage.IptvMediaStartType => await hostEngine.StartIptvMediaAsync(
                    request.Media!.ChannelId!.Value,
                    cancellationToken),
                DesktopMessage.IptvPublishStartType => await hostEngine.StartIptvPublishAsync(
                    request.Media!.ChannelId!.Value,
                    cancellationToken),
                DesktopMessage.IptvMediaStopType => await hostEngine.StopIptvMediaAsync(),
                DesktopMessage.IptvPublishStopType => await hostEngine.StopIptvMediaAsync(),
                DesktopMessage.IptvPublishStatusType => hostEngine.IptvMediaSnapshot(),
                DesktopMessage.IptvMediaStatusType => hostEngine.IptvMediaSnapshot(),
                _ => throw new MediaEngineException("media_runtime_unavailable"),
            };
            return (true, MediaResult(ResultType(request.Type), request.RequestId, snapshot));
        }
        catch (OperationCanceledException)
        {
            return (true, MediaError(ResultType(request.Type), request.RequestId, "media_cancelled"));
        }
        catch (MediaEngineException exception)
        {
            return (true, MediaError(ResultType(request.Type), request.RequestId, exception.Code));
        }
    }

    public static string CreateMediaStateEvent(MediaSnapshot snapshot, string? accountContextId = null)
    {
        return JsonSerializer.Serialize(new
        {
            type = DesktopMessage.IptvMediaStateType,
            protocolVersion = DesktopMessage.ProtocolVersion,
            state = snapshot.State,
            channelId = snapshot.ChannelId,
            channelName = snapshot.ChannelName,
            attempt = snapshot.Attempt,
            errorCode = snapshot.LastErrorCode,
            mode = snapshot.Mode,
            accountContextId,
        }, JsonOptions);
    }

    private static string CreatePong(string requestId)
    {
        var version = Assembly.GetEntryAssembly()?.GetName().Version?.ToString(3) ?? "0.0.0";
        return JsonSerializer.Serialize(new
        {
            type = DesktopMessage.PongType,
            requestId,
            protocolVersion = DesktopMessage.ProtocolVersion,
            desktop = true,
            platform = "windows",
            appVersion = version,
        }, JsonOptions);
    }

    private static string CreateHostStatus(string requestId, HostEngineSnapshot snapshot)
    {
        return JsonSerializer.Serialize(new
        {
            type = DesktopMessage.HostStatusResultType,
            requestId,
            protocolVersion = DesktopMessage.ProtocolVersion,
            host = new
            {
                state = snapshot.State,
                capabilities = snapshot.Capabilities,
                authorization = new
                {
                    authorized = snapshot.Authorization.Authorized,
                    permission = snapshot.Authorization.Permission,
                    transmissionInstanceId = snapshot.Authorization.TransmissionInstanceId,
                    transmissionRevision = snapshot.Authorization.TransmissionRevision,
                },
            },
        }, JsonOptions);
    }

    private static string CreateHostAuthorizeResult(
        string requestId,
        HostAuthorization authorization,
        bool authorized)
    {
        return JsonSerializer.Serialize(new
        {
            type = DesktopMessage.HostAuthorizeResultType,
            requestId,
            protocolVersion = DesktopMessage.ProtocolVersion,
            authorized,
            permission = authorization.Permission,
            transmissionInstanceId = authorization.TransmissionInstanceId,
            transmissionRevision = authorization.TransmissionRevision,
        }, JsonOptions);
    }

    private static string CreateHostClearResult(string requestId, HostEngine hostEngine)
    {
        hostEngine.ClearAuthorization();
        return JsonSerializer.Serialize(new
        {
            type = DesktopMessage.HostClearResultType,
            requestId,
            protocolVersion = DesktopMessage.ProtocolVersion,
            cleared = true,
        }, JsonOptions);
    }

    private static string CreateAccountActivateResult(string requestId, HostEngine hostEngine, string profileId)
    {
        try
        {
            var contextId = hostEngine.ActivateAccount(profileId);
            return JsonSerializer.Serialize(new
            {
                type = DesktopMessage.AccountActivateResultType,
                requestId,
                protocolVersion = DesktopMessage.ProtocolVersion,
                activated = true,
                accountContextId = contextId,
            }, JsonOptions);
        }
        catch
        {
            return JsonSerializer.Serialize(new
            {
                type = DesktopMessage.AccountActivateResultType,
                requestId,
                protocolVersion = DesktopMessage.ProtocolVersion,
                activated = false,
                errorCode = "account_context_unavailable",
            }, JsonOptions);
        }
    }

    private static string CreateAccountClearResult(string requestId, HostEngine hostEngine)
    {
        hostEngine.ClearAccount();
        return JsonSerializer.Serialize(new
        {
            type = DesktopMessage.AccountClearResultType,
            requestId,
            protocolVersion = DesktopMessage.ProtocolVersion,
            cleared = true,
        }, JsonOptions);
    }

    private static string PickFile(string requestId, HostEngine hostEngine, Func<string?> picker)
    {
        var path = picker();
        return path is null
            ? Success(DesktopMessage.IptvSourcesPickFileResultType, requestId, new { cancelled = true })
            : Success(DesktopMessage.IptvSourcesPickFileResultType, requestId, new
            {
                cancelled = false,
                source = hostEngine.AddIptvFile(path),
            });
    }

    private static string Success(string type, string requestId, object payload)
    {
        var payloadJson = JsonSerializer.SerializeToElement(payload, JsonOptions);
        var result = new Dictionary<string, object?>
        {
            ["type"] = type,
            ["requestId"] = requestId,
            ["protocolVersion"] = DesktopMessage.ProtocolVersion,
            ["ok"] = true,
        };
        foreach (var property in payloadJson.EnumerateObject())
        {
            result[property.Name] = property.Value.Clone();
        }
        return JsonSerializer.Serialize(result, JsonOptions);
    }

    private static string Error(string type, string requestId, string message)
    {
        return JsonSerializer.Serialize(new
        {
            type,
            requestId,
            protocolVersion = DesktopMessage.ProtocolVersion,
            ok = false,
            error = message,
        }, JsonOptions);
    }

    private static string MediaResult(string type, string requestId, MediaSnapshot snapshot)
    {
        return JsonSerializer.Serialize(new
        {
            type,
            requestId,
            protocolVersion = DesktopMessage.ProtocolVersion,
            ok = true,
            state = snapshot.State,
            channelId = snapshot.ChannelId,
            channelName = snapshot.ChannelName,
            attempt = snapshot.Attempt,
            errorCode = snapshot.LastErrorCode,
            mode = snapshot.Mode,
        }, JsonOptions);
    }

    private static string MediaError(string type, string requestId, string errorCode)
    {
        return JsonSerializer.Serialize(new
        {
            type,
            requestId,
            protocolVersion = DesktopMessage.ProtocolVersion,
            ok = false,
            errorCode,
        }, JsonOptions);
    }

    private static string ResultType(string requestType) => requestType switch
    {
        DesktopMessage.IptvSourcesListType => DesktopMessage.IptvSourcesListResultType,
        DesktopMessage.IptvSourcesAddType => DesktopMessage.IptvSourcesAddResultType,
        DesktopMessage.IptvSourcesRemoveType => DesktopMessage.IptvSourcesRemoveResultType,
        DesktopMessage.IptvSourcesRefreshType => DesktopMessage.IptvSourcesRefreshResultType,
        DesktopMessage.IptvSourcesPickFileType => DesktopMessage.IptvSourcesPickFileResultType,
        DesktopMessage.IptvCatalogGroupsType => DesktopMessage.IptvCatalogGroupsResultType,
        DesktopMessage.IptvCatalogSearchType => DesktopMessage.IptvCatalogSearchResultType,
        DesktopMessage.IptvMediaStartType => DesktopMessage.IptvMediaStartResultType,
        DesktopMessage.IptvMediaStopType => DesktopMessage.IptvMediaStopResultType,
        DesktopMessage.IptvMediaStatusType => DesktopMessage.IptvMediaStatusResultType,
        DesktopMessage.IptvPublishStartType => DesktopMessage.IptvPublishStartResultType,
        DesktopMessage.IptvPublishStopType => DesktopMessage.IptvPublishStopResultType,
        DesktopMessage.IptvPublishStatusType => DesktopMessage.IptvPublishStatusResultType,
        _ => "semyra.desktop.iptv.error",
    };
}
