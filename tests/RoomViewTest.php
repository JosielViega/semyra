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
        self::assertStringNotContainsString('room-player-mount', $html);
        self::assertStringNotContainsString('/assets/js/room-player.js', $html);
        self::assertStringNotContainsString('site-header', $html);
        self::assertStringNotContainsString('class="container"', $html);
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
        self::assertStringContainsString('data-initial-revision=""', $html);
        self::assertStringContainsString('/assets/js/room-shell.js', $html);
    }

    public function testOwnerSeesActiveTransmissionHudAndEndAction(): void
    {
        $html = $this->renderRoom([
            'identity' => $this->identity(),
            'transmission' => $this->transmission(true),
        ]);

        self::assertStringContainsString('class="room-shell has-transmission"', $html);
        self::assertStringContainsString('data-initial-video-id="M7lc1UVf-VE"', $html);
        self::assertStringContainsString('data-initial-revision="4"', $html);
        self::assertStringContainsString('Você está transmitindo', $html);
        self::assertMatchesRegularExpression('/data-end-transmission>/', $html);
        self::assertStringContainsString('Encerrar transmissão', $html);
        self::assertMatchesRegularExpression('/data-shared-playback-controls>/', $html);
        self::assertStringContainsString('data-playback-url="/room/7MKP3WQH/transmission/playback"', $html);
        self::assertStringContainsString('/assets/js/room-playback.js', $html);
        self::assertStringContainsString('/assets/js/room-media.js', $html);
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
        self::assertStringNotContainsString('data-debug-classification', $debug);
        self::assertStringContainsString('/assets/js/room-telemetry.js', $debug);
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
                'youtube_video_id' => 'M7lc1UVf-VE',
                'revision' => 1,
                'owner_name' => '<img src=x onerror=alert(4)>',
                'is_owner' => false,
                'media_mode' => 'vod',
                'playback' => ['state' => 'playing', 'position_ms' => 0, 'revision' => 1,
                    'at_live_edge' => false, 'live_edge_position_ms' => null],
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
    }

    private function renderRoom(array $overrides = []): string
    {
        return $this->view->render('pages/room', array_replace([
            'title' => 'Sala 7MKP3WQH — Semyra',
            'room' => ['code' => '7MKP3WQH'],
            'identity' => null,
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
        ];
    }

    private function transmission(bool $isOwner): array
    {
        return [
            'source' => 'youtube',
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
            ],
        ];
    }
}
