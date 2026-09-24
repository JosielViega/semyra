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

    public function testRendersRoomCodePlayerDataAndRoomScript(): void
    {
        $html = $this->view->render('pages/room', [
            'title' => 'Sala 7MKP3WQH — Semyra',
            'room' => [
                'code' => '7MKP3WQH',
                'youtube_video_id' => 'dQw4w9WgXcQ',
            ],
        ]);

        self::assertStringContainsString('7MKP3WQH', $html);
        self::assertStringContainsString('data-video-id="dQw4w9WgXcQ"', $html);
        self::assertStringContainsString('<script src="/assets/js/room-player.js" defer></script>', $html);
        self::assertStringNotContainsString('O player será adicionado na próxima etapa.', $html);
    }

    public function testEscapesRoomValuesInHtml(): void
    {
        $html = $this->view->render('pages/room', [
            'title' => 'Sala — Semyra',
            'room' => [
                'code' => '<script>alert(1)</script>',
                'youtube_video_id' => 'abc" onload="x',
            ],
        ]);

        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        self::assertStringContainsString('data-video-id="abc&quot; onload=&quot;x"', $html);
        self::assertStringNotContainsString('<script>alert(1)</script>', $html);
    }
}
