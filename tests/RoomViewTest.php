<?php

declare(strict_types=1);

namespace Tests;

use App\Core\View;
use PHPUnit\Framework\TestCase;

final class RoomViewTest extends TestCase
{
    private View $view;

    protected function setUp(): void
    {
        $this->view = new View(dirname(__DIR__) . '/resources/views');
    }

    public function testGuestSeesFullscreenJoinWithoutPlayer(): void
    {
        $html = $this->renderRoom();

        self::assertStringContainsString('class="room-body"', $html);
        self::assertStringContainsString('room-shell room-shell-join', $html);
        self::assertStringContainsString('Código da sala', $html);
        self::assertStringContainsString('7MKP3WQH', $html);
        self::assertStringContainsString('action="/room/7MKP3WQH/join"', $html);
        self::assertStringContainsString('name="display_name"', $html);
        self::assertStringContainsString('src="/assets/images/logo_semyra.png?v=8b-identity"', $html);
        self::assertStringContainsString('aria-label="Semyra — início"', $html);
        self::assertStringContainsString('alt="Semyra"', $html);
        self::assertStringNotContainsString('room-player-mount', $html);
        self::assertStringNotContainsString('/assets/js/room-player.js', $html);
        self::assertStringNotContainsString('site-header', $html);
        self::assertStringNotContainsString('class="container"', $html);
    }

    public function testAuthenticatedJoinUsesFixedEscapedAccountNameWithoutInput(): void
    {
        $html = $this->renderRoom([
            'currentUser' => ['id' => 7, 'display_name' => '<Josiel>'],
        ]);

        self::assertStringContainsString('Você entrará como <strong>&lt;Josiel&gt;</strong>', $html);
        self::assertStringContainsString('Entrar como &lt;Josiel&gt;', $html);
        self::assertStringNotContainsString('name="display_name"', $html);
    }

    public function testJoinedParticipantWithoutTransmissionSeesEmptyStateAndStartAction(): void
    {
        $html = $this->renderRoom(['identity' => $this->identity()]);

        self::assertStringContainsString('Nenhuma transmissão ativa', $html);
        self::assertStringContainsString('Iniciar transmissão', $html);
        self::assertStringContainsString('action="/room/7MKP3WQH/transmission"', $html);
        self::assertStringContainsString('id="room-transmission-dialog"', $html);
        self::assertStringContainsString('name="media_mode"', $html);
        self::assertMatchesRegularExpression('/value="vod"\s+checked/', $html);
        self::assertStringContainsString('value="live"', $html);
        self::assertStringContainsString('id="room-player-mount"', $html);
        self::assertStringContainsString('data-youtube-player-mount', $html);
        self::assertStringContainsString('data-livekit-player-mount hidden', $html);
        self::assertStringContainsString('data-livekit-viewer-token-url="/room/7MKP3WQH/livekit/viewer-token"', $html);
        self::assertStringContainsString('data-livekit-client-src="/assets/vendor/livekit/livekit-client.umd.js?v=2.22.3"', $html);
        self::assertStringContainsString('/assets/js/room-livekit-player.js?v=11n-screen-share-r3-7', $html);
        self::assertStringContainsString('/assets/js/room-desktop-iptv.js?v=11n-screen-share', $html);
        self::assertStringContainsString('data-initial-instance-id=""', $html);
        self::assertStringContainsString('data-initial-revision=""', $html);
        self::assertStringContainsString('/assets/css/tokens.css?v=8b-identity', $html);
        self::assertStringContainsString('/assets/css/room.css?v=12-1-ui-polish', $html);
        self::assertStringContainsString('data-livekit-voice-token-url="/room/7MKP3WQH/livekit/voice-token"', $html);
        self::assertStringContainsString('data-chat-messages-url="/room/7MKP3WQH/chat/messages"', $html);
        self::assertStringContainsString('data-room-panel="voice"', $html);
        self::assertStringContainsString('data-room-panel="chat"', $html);
        self::assertStringContainsString('/assets/js/room-voice.js?v=12-1-ui-polish', $html);
        self::assertStringContainsString('/assets/js/room-chat.js?v=12-chat-1', $html);
        self::assertStringContainsString('data-voice-toolbar-button data-microphone-state="muted"', $html);
        self::assertStringContainsString('data-voice-toolbar-icon href="#room-icon-mic-off"', $html);
        self::assertStringContainsString('aria-label="Ativar microfone"', $html);
        self::assertStringContainsString('aria-describedby="room-chat-help"', $html);
        self::assertStringContainsString('/assets/js/room-shell.js?v=11n-screen-r3-6', $html);
        self::assertStringContainsString('/assets/js/room-wake-lock.js?v=8b-identity', $html);
        self::assertStringContainsString('class="room-logo room-logo-empty"', $html);
        self::assertStringContainsString('/assets/images/logo_semyra_symbol.png?v=8b-polish-2', $html);
        self::assertStringContainsString('e assista junto com seus amigos.', $html);
        self::assertStringContainsString('<use href="#room-icon-video-plus"></use>', $html);
        self::assertStringNotContainsString('data-desktop-publish-tabs', $html);
        self::assertStringNotContainsString('data-iptv-source-panel', $html);
    }

    public function testAuthenticatedParticipantGetsDesktopIptvControlsInRoomMarkup(): void
    {
        $html = $this->renderRoom([
            'identity' => array_replace($this->identity(), ['user_id' => 7]),
            'currentUser' => ['id' => 7, 'display_name' => 'Josiel'],
            'can_publish_iptv' => true,
            'can_share_screen' => true,
        ]);

        self::assertStringContainsString('data-desktop-publish-tabs', $html);
        self::assertStringContainsString('data-iptv-source-panel-modern', $html);
        self::assertStringContainsString('>IPTV</button>', $html);
        self::assertStringContainsString('data-can-publish-iptv="1"', $html);
        self::assertStringContainsString('data-room-iptv-source-modern', $html);
        self::assertStringContainsString('data-iptv-tab="live"', $html);
        self::assertStringContainsString('data-iptv-tab="movies"', $html);
        self::assertStringContainsString('data-iptv-tab="series"', $html);
        self::assertStringContainsString('data-iptv-series-detail', $html);
        self::assertStringContainsString('role="tablist"', $html);
        self::assertStringContainsString('data-room-iptv-unavailable-modern hidden', $html);
        self::assertStringContainsString('Publicação IPTV indisponível neste Desktop.', $html);
        self::assertStringNotContainsString('recurso Premium', $html);
    }

    public function testIptvControlsAreOutsideTheYouTubeTransmissionForm(): void
    {
        $html = $this->renderRoom([
            'identity' => array_replace($this->identity(), ['user_id' => 7]),
            'currentUser' => ['id' => 7, 'display_name' => 'Josiel'],
            'can_publish_iptv' => true,
        ]);

        $youtubeStart = strpos($html, '<form id="room-youtube-transmission-form"');
        $youtubeEnd = strpos($html, '</form>', $youtubeStart === false ? 0 : $youtubeStart);
        $iptvStart = strpos($html, 'data-iptv-source-panel-modern');

        self::assertNotFalse($youtubeStart);
        self::assertNotFalse($youtubeEnd);
        self::assertNotFalse($iptvStart);
        self::assertLessThan($iptvStart, $youtubeEnd);
        self::assertStringContainsString('data-youtube-transmission-form', $html);
        self::assertMatchesRegularExpression('/<form[^>]+data-iptv-form="live"[^>]*>/', $html);
        self::assertMatchesRegularExpression('/<form[^>]+data-iptv-form="movies"[^>]*>/', $html);
        self::assertMatchesRegularExpression('/<form[^>]+data-iptv-form="series"[^>]*>/', $html);
    }

    public function testFreeAccountSeesPremiumGateWithoutCatalogControls(): void
    {
        $html = $this->renderRoom([
            'identity' => array_replace($this->identity(), ['user_id' => 7]),
            'currentUser' => ['id' => 7, 'display_name' => 'Josiel'],
            'can_publish_iptv' => false,
        ]);

        self::assertStringContainsString('IPTV · Premium', $html);
        self::assertStringContainsString('data-can-publish-iptv="0"', $html);
        self::assertStringContainsString('Transmitir sua IPTV para a sala é um recurso Premium.', $html);
        self::assertStringNotContainsString('data-room-iptv-source>', $html);
        self::assertStringNotContainsString('data-room-iptv-find', $html);
    }

    public function testPremiumAccountSeesScreenShareTabAndCaptureControls(): void
    {
        $html = $this->renderRoom([
            'identity' => array_replace($this->identity(), ['user_id' => 7]),
            'currentUser' => ['id' => 7, 'display_name' => 'Josiel'],
            'can_share_screen' => true,
        ]);

        self::assertStringContainsString('data-source-tab="screen">Tela</button>', $html);
        self::assertStringContainsString('data-can-share-screen="1"', $html);
        self::assertStringContainsString('data-screen-source-panel', $html);
        self::assertStringContainsString('data-screen-share-audio', $html);
        self::assertStringContainsString('data-screen-targets', $html);
        self::assertStringContainsString('data-screen-share-quality', $html);
        self::assertStringContainsString('<option value="standard" selected>', $html);
        self::assertStringContainsString('A captura é feita localmente pelo Semyra Desktop.', $html);
        self::assertStringContainsString('data-screen-share-start', $html);
        self::assertStringContainsString('/assets/js/room-screen-share.js?v=11n-screen-native-r3-6', $html);
        self::assertStringContainsString('/transmission/screen/heartbeat', $html);
        self::assertStringContainsString('/transmission/screen/stop', $html);
        self::assertStringNotContainsString('participant_token', $html);
    }

    public function testFreeAccountSeesScreenPremiumGateWithoutCaptureButton(): void
    {
        $html = $this->renderRoom([
            'identity' => array_replace($this->identity(), ['user_id' => 7]),
            'currentUser' => ['id' => 7, 'display_name' => 'Josiel'],
            'can_share_screen' => false,
        ]);

        self::assertStringContainsString('data-source-tab="screen">Tela · Premium</button>', $html);
        self::assertStringContainsString('data-can-share-screen="0"', $html);
        self::assertStringContainsString('Compartilhamento de tela é um recurso Premium.', $html);
        self::assertStringNotContainsString('data-screen-share-start', $html);
    }

    public function testOwnerSeesActiveTransmissionHudAndEndAction(): void
    {
        $html = $this->renderRoom([
            'identity' => $this->identity(),
            'transmission' => $this->transmission(true),
        ]);

        self::assertStringContainsString('class="room-shell has-transmission"', $html);
        self::assertStringContainsString('data-initial-video-id="M7lc1UVf-VE"', $html);
        self::assertStringContainsString('data-initial-instance-id="aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa"', $html);
        self::assertStringContainsString('data-initial-revision="4"', $html);
        self::assertStringContainsString('Você está transmitindo', $html);
        self::assertMatchesRegularExpression('/data-end-transmission>/', $html);
        self::assertStringContainsString('Encerrar transmissão', $html);
        self::assertStringContainsString('name="transmission_instance_id" value="aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa"', $html);
        self::assertStringContainsString('name="transmission_revision" value="4"', $html);
        self::assertMatchesRegularExpression('/data-shared-playback-controls>/', $html);
        self::assertStringContainsString('data-playback-sync aria-label="Sincronizar reprodução" hidden', $html);
        self::assertStringContainsString('data-playback-url="/room/7MKP3WQH/transmission/playback"', $html);
        self::assertStringContainsString('/assets/js/room-playback.js', $html);
        self::assertStringContainsString('class="room-player-bar"', $html);
        self::assertStringContainsString('/assets/js/room-media.js', $html);
        self::assertStringContainsString('data-volume-control', $html);
        self::assertStringContainsString('min="0" max="100" step="1" value="100"', $html);
        self::assertStringContainsString('/assets/js/room-player.js?v=10b21-instance', $html);
        self::assertStringContainsString('data-initial-live-sync-position-ms=""', $html);
        self::assertStringContainsString('data-panel-toggle="participants"', $html);
        self::assertStringContainsString('<use href="#room-icon-users"></use>', $html);
        self::assertStringContainsString('id="room-participant-count">0</strong>', $html);
        self::assertStringContainsString('data-icon-play', $html);
        self::assertStringContainsString('data-icon-pause', $html);
        self::assertStringContainsString('data-icon-muted', $html);
        self::assertStringContainsString('data-icon-audible', $html);
        self::assertStringContainsString('data-icon-maximize', $html);
        self::assertStringContainsString('data-icon-minimize', $html);
        self::assertStringContainsString('aria-pressed="true"', $html);
    }

    public function testViewerCanReplaceButCannotSeeEndAction(): void
    {
        $html = $this->renderRoom([
            'identity' => $this->identity(),
            'transmission' => $this->transmission(false),
        ]);

        self::assertStringContainsString('Pedro está transmitindo', $html);
        self::assertStringContainsString('Iniciar sua transmissão substituirá a transmissão atual.', $html);
        self::assertStringContainsString('data-end-transmission hidden', $html);
        self::assertStringContainsString('Iniciar minha transmissão', $html);
        self::assertMatchesRegularExpression('/data-shared-playback-controls>/', $html);
    }

    public function testIptvLiveStartsWithoutYouTubeIdAndHidesSharedPlaybackControls(): void
    {
        $transmission = $this->transmission(false);
        $transmission['source'] = 'iptv';
        $transmission['youtube_video_id'] = null;
        $transmission['media_mode'] = 'live';

        $html = $this->renderRoom([
            'identity' => $this->identity(),
            'transmission' => $transmission,
        ]);

        self::assertStringContainsString('data-initial-source="iptv"', $html);
        self::assertStringContainsString('data-initial-video-id=""', $html);
        self::assertStringContainsString('data-shared-playback-controls hidden', $html);
        self::assertStringNotContainsString('participant_token', $html);
        self::assertStringNotContainsString('publisher_identity', $html);
        self::assertStringNotContainsString('LIVEKIT_API_KEY', $html);
    }

    public function testIptvVodOwnerStartsWithSharedPlaybackControlsVisible(): void
    {
        $transmission = $this->transmission(true);
        $transmission['source'] = 'iptv';
        $transmission['youtube_video_id'] = null;
        $transmission['media_mode'] = 'vod';
        $transmission['playback']['position_ms'] = null;

        $html = $this->renderRoom([
            'identity' => $this->identity(),
            'transmission' => $transmission,
        ]);

        self::assertStringContainsString('data-initial-source="iptv"', $html);
        self::assertStringContainsString('data-initial-playback-position-ms=""', $html);
        self::assertMatchesRegularExpression('/data-shared-playback-controls>/', $html);
    }

    public function testIptvVodViewerDoesNotSeeSharedPlaybackControls(): void
    {
        $transmission = $this->transmission(false);
        $transmission['source'] = 'iptv';
        $transmission['youtube_video_id'] = null;
        $transmission['media_mode'] = 'vod';

        $html = $this->renderRoom([
            'identity' => $this->identity(),
            'transmission' => $transmission,
        ]);

        self::assertStringContainsString('data-shared-playback-controls hidden', $html);
    }

    public function testTelemetryIsRenderedOnlyInDebugMode(): void
    {
        $normal = $this->renderRoom(['identity' => $this->identity()]);
        $debug = $this->renderRoom(['identity' => $this->identity(), 'debug' => true]);

        self::assertStringNotContainsString('data-room-telemetry', $normal);
        self::assertStringNotContainsString('/assets/js/room-telemetry.js', $normal);
        self::assertStringNotContainsString('data-debug-media-mode', $normal);
        self::assertStringContainsString('data-room-telemetry', $debug);
        self::assertStringContainsString('Diagnóstico', $debug);
        self::assertStringContainsString('Estado da mídia', $debug);
        self::assertStringContainsString('data-debug-media-mode', $debug);
        self::assertStringContainsString('data-debug-at-live-edge', $debug);
        self::assertStringContainsString('data-debug-live-sync-target', $debug);
        self::assertStringContainsString('data-debug-official-position', $debug);
        self::assertStringContainsString('data-debug-local-drift', $debug);
        self::assertStringContainsString('data-debug-wake-lock', $debug);
        self::assertStringNotContainsString('data-debug-classification', $debug);
        self::assertStringContainsString('/assets/js/room-telemetry.js?v=8b-identity', $debug);
    }

    public function testRoomUsesLocalSvgIconSystemWithoutLegacyGlyphs(): void
    {
        $html = $this->renderRoom([
            'identity' => $this->identity(),
            'participants' => [['name' => 'Josiel', 'is_you' => true]],
            'transmission' => $this->transmission(true),
        ]);

        foreach (['users', 'share', 'video-plus', 'sync', 'play', 'pause', 'volume-x',
            'volume', 'maximize', 'minimize', 'x', 'copy', 'stop'] as $icon) {
            self::assertStringContainsString('id="room-icon-' . $icon . '"', $html);
        }
        foreach (['🔇', '🔊', '↗', '＋', '↻', '▶', 'Ⅱ', '⛶', '>×<'] as $legacyGlyph) {
            self::assertStringNotContainsString($legacyGlyph, $html);
        }
        self::assertStringContainsString('/assets/js/room-media.js?v=10b21-instance', $html);
        self::assertStringContainsString('/assets/js/room-playback.js?v=11m-c-vod-controls-r7', $html);
        self::assertStringContainsString('data-leave-url="/room/7MKP3WQH/leave"', $html);
        self::assertStringContainsString('/assets/js/room-presence.js?v=11n-screen-r3-6', $html);
    }

    public function testEscapesRoomParticipantOwnerAndFlashValues(): void
    {
        $html = $this->renderRoom([
            'room' => ['code' => '<script>alert(1)</script>'],
            'identity' => [
                'participant_key' => str_repeat('b', 64),
                'display_name' => '<img src=x onerror=alert(2)>',
            ],
            'participants' => [['name' => '<svg onload=alert(3)>', 'is_you' => true]],
            'transmission' => [
                'source' => 'youtube',
                'instance_id' => str_repeat('a', 32),
                'youtube_video_id' => 'M7lc1UVf-VE',
                'revision' => 1,
                'owner_name' => '<img src=x onerror=alert(4)>',
                'is_owner' => false,
                'media_mode' => 'vod',
                'playback' => ['state' => 'playing', 'position_ms' => 0, 'revision' => 1,
                    'at_live_edge' => false, 'live_edge_position_ms' => null,
                    'live_sync_position_ms' => null, 'live_sync_delay_ms' => null],
            ],
            'flashes' => ['error' => ['<script>alert(5)</script>']],
        ]);

        foreach ([
            '&lt;script&gt;alert(1)&lt;/script&gt;',
            '&lt;svg onload=alert(3)&gt;',
            '&lt;img src=x onerror=alert(4)&gt;',
            '&lt;script&gt;alert(5)&lt;/script&gt;',
        ] as $escaped) {
            self::assertStringContainsString($escaped, $html);
        }
        self::assertStringNotContainsString('<script>alert(1)</script>', $html);
        self::assertStringNotContainsString('<svg onload=alert(3)>', $html);
        self::assertStringNotContainsString('<img src=x onerror=alert(4)>', $html);
        self::assertStringContainsString('data-room-flash data-flash-type="error"', $html);
    }

    public function testIptvCatalogOwnsScrollAndPaginationCannotBeCoveredByDialogActions(): void
    {
        $css = file_get_contents(dirname(__DIR__) . '/public/assets/css/room.css');

        self::assertIsString($css);
        self::assertMatchesRegularExpression('/\.room-iptv-catalog-scroller \{[^}]*flex: 1 1 auto;[^}]*min-height: 0;[^}]*overflow-x: hidden;[^}]*overflow-y: auto;[^}]*\}/s', $css);
        self::assertMatchesRegularExpression('/\.room-iptv-grid \{[^}]*grid-auto-rows: max-content;[^}]*overflow: visible;[^}]*\}/s', $css);
        self::assertMatchesRegularExpression('/\.room-iptv-pagination \{[^}]*position: relative;[^}]*flex: 0 0 auto;[^}]*\}/s', $css);
        self::assertMatchesRegularExpression('/\.room-iptv-dialog-actions \{[^}]*position: relative;[^}]*flex: 0 0 auto;[^}]*\}/s', $css);
        self::assertStringNotContainsString('.room-iptv-pagination { position: sticky;', $css);
        self::assertStringNotContainsString('.room-iptv-dialog-actions { position: sticky;', $css);
        self::assertStringNotContainsString('pointer-events: none', substr($css, (int) strpos($css, '.room-iptv-pagination'), 500));
        self::assertStringContainsString('.room-iptv-grid-live { grid-template-columns: repeat(3, minmax(0, 1fr)); }', $css);
        self::assertStringContainsString('.room-iptv-grid-posters { grid-template-columns: repeat(5, minmax(0, 1fr)); }', $css);
        self::assertStringContainsString('@media (max-width: 860px)', $css);
        self::assertStringContainsString('@media (max-width: 720px)', $css);
        self::assertStringContainsString('@media (max-width: 600px)', $css);
        self::assertStringContainsString('.room-iptv-grid-posters { grid-template-columns: repeat(4, minmax(0, 1fr)); }', $css);
        self::assertStringContainsString('.room-iptv-grid-posters { grid-template-columns: repeat(3, minmax(0, 1fr)); }', $css);
        self::assertStringContainsString('.room-iptv-grid-posters { grid-template-columns: repeat(2, minmax(0, 1fr)); }', $css);
        self::assertMatchesRegularExpression('/\.room-iptv-poster-title \{[^}]*-webkit-line-clamp: 2;[^}]*\}/s', $css);
        self::assertMatchesRegularExpression('/\.room-iptv-season-tabs \{[^}]*flex: 0 0 auto;[^}]*overflow-x: auto;[^}]*overflow-y: visible;[^}]*\}/s', $css);
        self::assertMatchesRegularExpression('/\.room-iptv-series-detail \{[^}]*overflow: visible;[^}]*\}/s', $css);
        self::assertMatchesRegularExpression('/\.room-iptv-episode-list \{[^}]*overflow-y: auto;[^}]*\}/s', $css);
    }

    private function renderRoom(array $overrides = []): string
    {
        return $this->view->render('pages/room', array_replace([
            'title' => 'Sala 7MKP3WQH — Semyra',
            'room' => ['code' => '7MKP3WQH'],
            'identity' => null,
            'currentUser' => null,
            'participants' => [],
            'transmission' => null,
            'debug' => false,
            'flashes' => [],
            'csrfField' => '<input type="hidden" name="_token" value="csrf-token">',
            'csrfToken' => 'csrf-token',
        ], $overrides), 'layouts/room');
    }

    private function identity(): array
    {
        return [
            'participant_key' => str_repeat('a', 64),
            'display_name' => 'Josiel',
            'user_id' => null,
        ];
    }

    private function transmission(bool $isOwner): array
    {
        return [
            'source' => 'youtube',
            'instance_id' => str_repeat('a', 32),
            'youtube_video_id' => 'M7lc1UVf-VE',
            'revision' => 4,
            'owner_name' => 'Pedro',
            'is_owner' => $isOwner,
            'media_mode' => 'vod',
            'playback' => [
                'state' => 'playing',
                'position_ms' => 125430,
                'revision' => 7,
                'at_live_edge' => false,
                'live_edge_position_ms' => null,
                'live_sync_position_ms' => null,
                'live_sync_delay_ms' => null,
            ],
        ];
    }
}
