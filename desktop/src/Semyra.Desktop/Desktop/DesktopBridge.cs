using System.Reflection;
using System.Text.Json;
using Semyra.Desktop.Host;
using Semyra.Desktop.Iptv;
using Semyra.Desktop.Media;

namespace Semyra.Desktop.Desktop;

public static class DesktopBridge
{
    public const string PlayerUserActivityType = "semyra.desktop.player.user-activity";
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

        if (request.Type == DesktopMessage.HostClearType)
        {
            return (true, await CreateHostClearResultAsync(request.RequestId, hostEngine));
        }
        if (request.Type == DesktopMessage.AccountActivateType)
        {
            return (true, await CreateAccountActivateResultAsync(request.RequestId, hostEngine, request.Account!.ProfileId!));
        }
        if (request.Type == DesktopMessage.AccountClearType)
        {
            return (true, await CreateAccountClearResultAsync(request.RequestId, hostEngine));
        }

        if (request.Iptv is null)
        {
            if (request.Player is not null)
            {
                return await HandlePlayerAsync(request, hostEngine, cancellationToken);
            }
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
                DesktopMessage.IptvSourcesAddXtreamType => Success(
                    DesktopMessage.IptvSourcesAddXtreamResultType,
                    request.RequestId,
                    new
                    {
                        source = await hostEngine.AddIptvXtreamAsync(
                            request.Iptv.Name!,
                            request.Iptv.BaseUrl!,
                            request.Iptv.Username!,
                            request.Iptv.Password!,
                            cancellationToken),
                    }),
                DesktopMessage.IptvSourcesRemoveType => Success(
                    DesktopMessage.IptvSourcesRemoveResultType,
                    request.RequestId,
                    new { removed = hostEngine.RemoveIptvSource(request.Iptv.SourceId!.Value) }),
                DesktopMessage.IptvSourcesRefreshType => Success(
                    DesktopMessage.IptvSourcesRefreshResultType,
                    request.RequestId,
                    new { source = await hostEngine.RefreshIptvSourceAsync(request.Iptv.SourceId!.Value, cancellationToken) }),
                DesktopMessage.IptvVodRefreshType => Success(DesktopMessage.IptvVodRefreshResultType, request.RequestId, new { source = await hostEngine.SyncIptvVodAsync(request.Iptv.SourceId!.Value, cancellationToken) }),
                DesktopMessage.IptvVodCategoriesType => Success(DesktopMessage.IptvVodCategoriesResultType, request.RequestId, new { categories = hostEngine.GetIptvVodCategories(request.Iptv.SourceId!.Value, request.Iptv.MediaType == "movie" ? IptvVodMediaType.Movie : IptvVodMediaType.Series) }),
                DesktopMessage.IptvVodMoviesSearchType => Success(DesktopMessage.IptvVodMoviesSearchResultType, request.RequestId, hostEngine.SearchIptvMovies(request.Iptv.SourceId!.Value, request.Iptv.Query, request.Iptv.Category, request.Iptv.Offset, request.Iptv.Limit)),
                DesktopMessage.IptvVodSeriesSearchType => Success(DesktopMessage.IptvVodSeriesSearchResultType, request.RequestId, hostEngine.SearchIptvSeries(request.Iptv.SourceId!.Value, request.Iptv.Query, request.Iptv.Category, request.Iptv.Offset, request.Iptv.Limit)),
                DesktopMessage.IptvVodSeriesDetailsType => Success(DesktopMessage.IptvVodSeriesDetailsResultType, request.RequestId, await hostEngine.GetIptvSeriesDetailsAsync(request.Iptv.SeriesId!.Value, request.Iptv.Refresh, cancellationToken)),
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
                DesktopMessage.IptvFavoritesListType => Success(
                    DesktopMessage.IptvFavoritesListResultType,
                    request.RequestId,
                    hostEngine.ListIptvFavorites(
                        request.Iptv.SourceId!.Value,
                        request.Iptv.Offset,
                        request.Iptv.Limit)),
                DesktopMessage.IptvRecentsListType => Success(
                    DesktopMessage.IptvRecentsListResultType,
                    request.RequestId,
                    new { channels = hostEngine.ListIptvRecents(request.Iptv.SourceId!.Value) }),
                DesktopMessage.IptvRecentRemoveType => Success(
                    DesktopMessage.IptvRecentRemoveResultType,
                    request.RequestId,
                    new
                    {
                        sourceId = request.Iptv.SourceId!.Value,
                        recentId = request.Iptv.RecentId!.Value,
                        removed = hostEngine.RemoveIptvRecent(
                            request.Iptv.SourceId.Value,
                            request.Iptv.RecentId.Value),
                    }),
                DesktopMessage.IptvRecentsClearType => Success(
                    DesktopMessage.IptvRecentsClearResultType,
                    request.RequestId,
                    new
                    {
                        sourceId = request.Iptv.SourceId!.Value,
                        removedCount = hostEngine.ClearIptvRecents(request.Iptv.SourceId.Value),
                    }),
                DesktopMessage.IptvFavoriteSetType => Success(
                    DesktopMessage.IptvFavoriteSetResultType,
                    request.RequestId,
                    new
                    {
                        channelId = request.Iptv.ChannelId!.Value,
                        favorite = hostEngine.SetIptvFavorite(request.Iptv.ChannelId.Value, request.Iptv.Favorite!.Value),
                    }),
                _ => throw new InvalidOperationException("Comando IPTV não reconhecido."),
            });
        }
        catch (OperationCanceledException)
        {
            return (true, Error(ResultType(request.Type), request.RequestId, "Operação cancelada."));
        }
        catch (Exception exception) when (exception is IptvValidationException or IptvRefreshException or XtreamException)
        {
            return (true, Error(ResultType(request.Type), request.RequestId, exception.Message));
        }
        catch
        {
            return (true, Error(ResultType(request.Type), request.RequestId, "Não foi possível concluir a operação local."));
        }
    }

    private static async Task<(bool Handled, string Response)> HandlePlayerAsync(
        DesktopRequest request,
        HostEngine hostEngine,
        CancellationToken cancellationToken)
    {
        var player = request.Player!;
        if (!hostEngine.IsCurrentAccountContext(player.AccountContextId))
            return (true, MediaError(ResultType(request.Type), request.RequestId, "account_context_changed"));
        try
        {
            var snapshot = request.Type switch
            {
                DesktopMessage.IptvPlayerControlType => await hostEngine.ControlMpvAsync(
                    player.SessionId, player.Command!, player.Value, cancellationToken),
                DesktopMessage.IptvPlayerGeometryType => hostEngine.UpdateMpvGeometry(
                    player.SessionId,
                    new MpvGeometry(player.X, player.Y, player.Width, player.Height, player.ViewportWidth, player.ViewportHeight, player.Visible)),
                DesktopMessage.IptvPlayerFullscreenType => hostEngine.SetMpvFullscreen(
                    player.SessionId, player.Fullscreen!.Value),
                _ => throw new MediaEngineException("invalid_player_control"),
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
            if (request.Type == DesktopMessage.IptvViewStartType)
            {
                var result = await hostEngine.StartIptvLocalViewAsync(request.Media!.ChannelId!.Value, cancellationToken);
                return (true, LocalViewResult(ResultType(request.Type), request.RequestId, result));
            }
            var snapshot = request.Type switch
            {
                DesktopMessage.IptvPublishStartType => await hostEngine.StartIptvPublishAsync(
                    request.Media!.ChannelId!.Value,
                    cancellationToken),
                DesktopMessage.IptvViewStopType => await hostEngine.StopIptvMediaAsync(),
                DesktopMessage.IptvViewStatusType => hostEngine.IptvMediaSnapshot(),
                DesktopMessage.IptvPublishStopType => await hostEngine.StopIptvMediaAsync(),
                DesktopMessage.IptvPublishStatusType => hostEngine.IptvMediaSnapshot(),
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
            playbackKind = snapshot.PlaybackKind,
            sessionId = snapshot.SessionId,
            position = snapshot.Position,
            duration = snapshot.Duration,
            seekable = snapshot.Seekable,
            muted = snapshot.Muted,
            volume = snapshot.Volume,
            container = snapshot.Container,
            videoCodec = snapshot.VideoCodec,
            audioCodec = snapshot.AudioCodec,
            mediaKind = snapshot.MediaKind,
            fullscreen = snapshot.Fullscreen,
            paused = snapshot.Paused,
            buffering = snapshot.Buffering,
            accountContextId,
        }, JsonOptions);
    }

    public static string CreatePlayerUserActivityEvent() => JsonSerializer.Serialize(new
    {
        type = PlayerUserActivityType,
    }, JsonOptions);

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

    private static async Task<string> CreateHostClearResultAsync(string requestId, HostEngine hostEngine)
    {
        await hostEngine.ClearAuthorizationAsync();
        return JsonSerializer.Serialize(new
        {
            type = DesktopMessage.HostClearResultType,
            requestId,
            protocolVersion = DesktopMessage.ProtocolVersion,
            cleared = true,
        }, JsonOptions);
    }

    private static async Task<string> CreateAccountActivateResultAsync(string requestId, HostEngine hostEngine, string profileId)
    {
        try
        {
            var contextId = await hostEngine.ActivateAccountAsync(profileId);
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

    private static async Task<string> CreateAccountClearResultAsync(string requestId, HostEngine hostEngine)
    {
        await hostEngine.ClearAccountAsync();
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
            playbackKind = snapshot.PlaybackKind,
            sessionId = snapshot.SessionId,
            position = snapshot.Position,
            duration = snapshot.Duration,
            seekable = snapshot.Seekable,
            muted = snapshot.Muted,
            volume = snapshot.Volume,
            container = snapshot.Container,
            videoCodec = snapshot.VideoCodec,
            audioCodec = snapshot.AudioCodec,
            mediaKind = snapshot.MediaKind,
            fullscreen = snapshot.Fullscreen,
            paused = snapshot.Paused,
            buffering = snapshot.Buffering,
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

    private static string LocalViewResult(string type, string requestId, MpvPlaybackStartResult result)
    {
        var snapshot = result.Snapshot;
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
            playbackKind = result.PlaybackKind,
            sessionId = result.SessionId,
        }, JsonOptions);
    }

    private static string ResultType(string requestType) => requestType switch
    {
        DesktopMessage.IptvSourcesListType => DesktopMessage.IptvSourcesListResultType,
        DesktopMessage.IptvSourcesAddType => DesktopMessage.IptvSourcesAddResultType,
        DesktopMessage.IptvSourcesAddXtreamType => DesktopMessage.IptvSourcesAddXtreamResultType,
        DesktopMessage.IptvSourcesRemoveType => DesktopMessage.IptvSourcesRemoveResultType,
        DesktopMessage.IptvSourcesRefreshType => DesktopMessage.IptvSourcesRefreshResultType,
        DesktopMessage.IptvVodRefreshType => DesktopMessage.IptvVodRefreshResultType,
        DesktopMessage.IptvVodCategoriesType => DesktopMessage.IptvVodCategoriesResultType,
        DesktopMessage.IptvVodMoviesSearchType => DesktopMessage.IptvVodMoviesSearchResultType,
        DesktopMessage.IptvVodSeriesSearchType => DesktopMessage.IptvVodSeriesSearchResultType,
        DesktopMessage.IptvVodSeriesDetailsType => DesktopMessage.IptvVodSeriesDetailsResultType,
        DesktopMessage.IptvSourcesPickFileType => DesktopMessage.IptvSourcesPickFileResultType,
        DesktopMessage.IptvCatalogGroupsType => DesktopMessage.IptvCatalogGroupsResultType,
        DesktopMessage.IptvCatalogSearchType => DesktopMessage.IptvCatalogSearchResultType,
        DesktopMessage.IptvFavoritesListType => DesktopMessage.IptvFavoritesListResultType,
        DesktopMessage.IptvFavoriteSetType => DesktopMessage.IptvFavoriteSetResultType,
        DesktopMessage.IptvRecentsListType => DesktopMessage.IptvRecentsListResultType,
        DesktopMessage.IptvRecentRemoveType => DesktopMessage.IptvRecentRemoveResultType,
        DesktopMessage.IptvRecentsClearType => DesktopMessage.IptvRecentsClearResultType,
        DesktopMessage.IptvViewStartType => DesktopMessage.IptvViewStartResultType,
        DesktopMessage.IptvViewStopType => DesktopMessage.IptvViewStopResultType,
        DesktopMessage.IptvViewStatusType => DesktopMessage.IptvViewStatusResultType,
        DesktopMessage.IptvPlayerControlType => DesktopMessage.IptvPlayerControlResultType,
        DesktopMessage.IptvPlayerGeometryType => DesktopMessage.IptvPlayerGeometryResultType,
        DesktopMessage.IptvPlayerFullscreenType => DesktopMessage.IptvPlayerFullscreenResultType,
        DesktopMessage.IptvPublishStartType => DesktopMessage.IptvPublishStartResultType,
        DesktopMessage.IptvPublishStopType => DesktopMessage.IptvPublishStopResultType,
        DesktopMessage.IptvPublishStatusType => DesktopMessage.IptvPublishStatusResultType,
        _ => "semyra.desktop.iptv.error",
    };
}
