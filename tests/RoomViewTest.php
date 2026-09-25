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

    public function testGuestSeesOnlyTheJoinExperience(): void
    {
        $html = $this->renderRoom();

        self::assertStringContainsString('7MKP3WQH', $html);
        self::assertStringContainsString('action="/room/7MKP3WQH/join"', $html);
        self::assertStringContainsString('name="display_name"', $html);
        self::assertStringContainsString('maxlength="30"', $html);
        self::assertStringContainsString('name="_token" value="csrf-token"', $html);
        self::assertStringNotContainsString('data-video-id=', $html);
        self::assertStringNotContainsString('data-room-share', $html);
        self::assertStringNotContainsString('data-room-presence', $html);
        self::assertStringNotContainsString('data-room-telemetry', $html);
        self::assertStringNotContainsString('/assets/js/room-player.js', $html);
        self::assertStringNotContainsString('/assets/js/room-share.js', $html);
        self::assertStringNotContainsString('/assets/js/room-presence.js', $html);
        self::assertStringNotContainsString('/assets/js/room-telemetry.js', $html);
    }

    public function testJoinedParticipantSeesPlayerSharingPresenceAndScripts(): void
    {
        $html = $this->renderRoom([
            'identity' => [
                'participant_key' => str_repeat('a', 64),
                'display_name' => 'Josiel',
            ],
            'participants' => [
                ['name' => 'Josiel', 'is_you' => true],
                ['name' => 'Pedro', 'is_you' => false],
            ],
        ]);

        self::assertStringContainsString('Você entrou como <strong>Josiel</strong>', $html);
        self::assertStringContainsString('data-video-id="dQw4w9WgXcQ"', $html);
        self::assertStringContainsString('data-room-share', $html);
        self::assertStringContainsString('data-room-code="7MKP3WQH"', $html);
        self::assertStringContainsString('data-room-presence', $html);
        self::assertStringContainsString('data-presence-url="/room/7MKP3WQH/presence"', $html);
        self::assertStringContainsString('data-csrf-token="csrf-token"', $html);
        self::assertStringContainsString('data-room-telemetry', $html);
        self::assertStringContainsString('Reprodução observada', $html);
        self::assertStringContainsString('Nenhuma sincronização automática é aplicada nesta etapa.', $html);
        self::assertStringContainsString('<span>Josiel</span><strong> (você)</strong>', $html);
        self::assertStringContainsString('<span>Pedro</span>', $html);
        self::assertStringContainsString('<script src="/assets/js/room-player.js" defer></script>', $html);
        self::assertStringContainsString('<script src="/assets/js/room-share.js" defer></script>', $html);
        self::assertStringContainsString('<script src="/assets/js/room-telemetry.js" defer></script>', $html);
        self::assertStringContainsString('<script src="/assets/js/room-presence.js" defer></script>', $html);
        self::assertLessThan(
            strpos($html, '/assets/js/room-presence.js'),
            strpos($html, '/assets/js/room-telemetry.js'),
        );
        self::assertStringNotContainsString('action="/room/7MKP3WQH/join"', $html);
    }

    public function testEscapesRoomIdentityParticipantAndFlashValuesInHtml(): void
    {
        $html = $this->renderRoom([
            'room' => [
                'code' => '<script>alert(1)</script>',
                'youtube_video_id' => 'abc" onload="x',
            ],
            'identity' => [
                'participant_key' => str_repeat('b', 64),
                'display_name' => '<img src=x onerror=alert(1)>',
            ],
            'participants' => [
                ['name' => '<svg onload=alert(2)>', 'is_you' => true],
            ],
            'flashes' => ['error' => ['<script>alert(3)</script>']],
        ]);

        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        self::assertStringContainsString('data-video-id="abc&quot; onload=&quot;x"', $html);
        self::assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;', $html);
        self::assertStringContainsString('&lt;svg onload=alert(2)&gt;', $html);
        self::assertStringContainsString('&lt;script&gt;alert(3)&lt;/script&gt;', $html);
        self::assertStringNotContainsString('<script>alert(1)</script>', $html);
        self::assertStringNotContainsString('<img src=x onerror=alert(1)>', $html);
        self::assertStringNotContainsString('<svg onload=alert(2)>', $html);
        self::assertStringNotContainsString('<script>alert(3)</script>', $html);
    }

    private function renderRoom(array $overrides = []): string
    {
        return $this->view->render('pages/room', array_replace([
            'title' => 'Sala 7MKP3WQH — Semyra',
            'room' => [
                'code' => '7MKP3WQH',
                'youtube_video_id' => 'dQw4w9WgXcQ',
            ],
            'identity' => null,
            'participants' => [],
            'flashes' => [],
            'csrfField' => '<input type="hidden" name="_token" value="csrf-token">',
            'csrfToken' => 'csrf-token',
        ], $overrides));
    }
}
