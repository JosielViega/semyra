<?php

declare(strict_types=1);

namespace Tests;

use App\Core\View;
use PHPUnit\Framework\TestCase;

final class DesktopIptvViewTest extends TestCase
{
    public function testDesktopCatalogPageKeepsBrowserFallbackAndNativeControls(): void
    {
        $html = (new View(dirname(__DIR__) . '/resources/views'))->render('pages/desktop-iptv', [
            'title' => 'Fontes IPTV — Semyra',
            'csrfField' => '',
            'currentUser' => null,
        ]);

        self::assertStringContainsString('disponível no Semyra Desktop', $html);
        self::assertStringContainsString('data-iptv-pick-file', $html);
        self::assertStringContainsString('data-iptv-url-form', $html);
        self::assertStringContainsString('/assets/js/desktop-iptv.js', $html);
        self::assertStringContainsString('/assets/vendor/hls/hls.min.js?v=1.7.3', $html);
        self::assertStringContainsString('data-iptv-video controls playsinline', $html);
        self::assertStringContainsString("'button-secondary', 'Assistir'", file_get_contents(dirname(__DIR__) . '/public/assets/js/desktop-iptv.js'));
        self::assertStringNotContainsString('streamUrl', $html);
    }
}
