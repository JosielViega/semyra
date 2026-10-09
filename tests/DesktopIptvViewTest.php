<?php

declare(strict_types=1);

namespace Tests;

use App\Core\View;
use PHPUnit\Framework\TestCase;

final class DesktopIptvViewTest extends TestCase
{
    public function testDesktopCatalogPageProvidesCinematicAccessibleLocalPlayer(): void
    {
        $html = (new View(dirname(__DIR__) . '/resources/views'))->render('pages/desktop-iptv', [
            'title' => 'Fontes IPTV — Semyra',
            'csrfField' => '',
            'currentUser' => null,
            'pageStyles' => ['/assets/css/desktop-iptv.css?v=11h-b-settings'],
        ]);

        self::assertStringContainsString('Abra esta página no Semyra Desktop', $html);
        self::assertStringContainsString('/assets/css/desktop-iptv.css?v=11h-b-settings', $html);
        self::assertStringContainsString('data-iptv-pick-file', $html);
        self::assertStringContainsString('data-iptv-url-form', $html);
        self::assertStringContainsString('data-iptv-settings', $html);
        self::assertStringContainsString('data-iptv-onboarding', $html);
        self::assertStringContainsString('data-iptv-channel-list', $html);
        self::assertStringContainsString('data-iptv-channel-list aria-live="polite" aria-busy="false"', $html);
        self::assertStringContainsString('data-iptv-source-loading aria-live="polite" aria-busy="true"', $html);
        self::assertStringContainsString('data-iptv-browser-notice role="status" hidden', $html);
        self::assertStringContainsString('/assets/js/desktop-iptv.js?v=11h-b-settings', $html);
        self::assertStringContainsString('data-settings-state="idle"', $html);
        self::assertStringContainsString('data-iptv-settings-content', $html);
        self::assertStringContainsString('data-iptv-settings-loader role="status" aria-live="polite" hidden', $html);
        self::assertStringContainsString('Adicionar arquivo M3U', $html);
        self::assertStringNotContainsString('>Arquivo M3U</button>', $html);
        self::assertStringContainsString('/assets/vendor/hls/hls.min.js?v=1.7.3', $html);
        self::assertStringContainsString('data-iptv-video playsinline', $html);
        self::assertStringNotContainsString('<video data-iptv-video controls', $html);
        self::assertStringContainsString('data-iptv-mute', $html);
        self::assertStringContainsString('data-iptv-volume', $html);
        self::assertStringContainsString('data-iptv-fullscreen', $html);
        self::assertStringContainsString('data-iptv-media-stop', $html);
        self::assertStringContainsString('aria-live="polite"', $html);
        self::assertStringNotContainsString('streamUrl', $html);
    }
}
