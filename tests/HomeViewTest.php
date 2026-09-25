<?php

declare(strict_types=1);

namespace Tests;

use App\Core\View;
use PHPUnit\Framework\TestCase;

final class HomeViewTest extends TestCase
{
    public function testRoomCreationRequiresOnlyCsrfAndSubmit(): void
    {
        $view = new View(dirname(__DIR__) . '/resources/views');
        $html = $view->render('pages/home', [
            'title' => 'Semyra',
            'appName' => 'Semyra',
            'csrfField' => '<input type="hidden" name="_token" value="csrf-token">',
            'flashes' => [],
        ]);

        self::assertStringContainsString('action="/rooms"', $html);
        self::assertStringContainsString('Criar sala', $html);
        self::assertStringContainsString('name="_token" value="csrf-token"', $html);
        self::assertStringNotContainsString('youtube_url', $html);
        self::assertStringNotContainsString('URL do vídeo', $html);
    }
}
