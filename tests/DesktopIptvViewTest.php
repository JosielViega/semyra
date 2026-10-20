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
            'pageStyles' => ['/assets/css/desktop-iptv.css?v=11l-a-structured-vod'],
        ]);

        self::assertStringContainsString('Abra esta página no Semyra Desktop', $html);
        self::assertStringContainsString('/assets/css/desktop-iptv.css?v=11l-a-structured-vod', $html);
        self::assertStringContainsString('/assets/js/desktop-bridge.js?v=11l-a-structured-vod', $html);
        self::assertStringContainsString('data-iptv-pick-file', $html);
        self::assertStringContainsString('data-iptv-url-form', $html);
        self::assertStringContainsString('data-iptv-settings', $html);
        self::assertStringContainsString('data-iptv-onboarding', $html);
        self::assertStringContainsString('data-iptv-channel-list', $html);
        self::assertStringContainsString('data-iptv-channel-list aria-live="polite" aria-busy="false"', $html);
        self::assertStringContainsString('data-iptv-source-loading aria-live="polite" aria-busy="true"', $html);
        self::assertStringContainsString('data-iptv-browser-notice role="status" hidden', $html);
        self::assertStringContainsString('/assets/js/desktop-iptv.js?v=11l-a-structured-vod', $html);
        self::assertStringContainsString('data-iptv-xtream-form', $html);
        self::assertStringContainsString('type="password"', $html);
        self::assertStringContainsString('Adicionar Xtream', $html);
        self::assertStringContainsString('data-iptv-recents-clear', $html);
        self::assertStringContainsString('data-iptv-recents-feedback role="status" aria-live="polite"', $html);
        self::assertStringContainsString('id="iptv-icon-play"', $html);
        self::assertStringContainsString('id="iptv-icon-pause"', $html);
        self::assertStringContainsString('id="iptv-icon-volume"', $html);
        self::assertStringContainsString('id="iptv-icon-muted"', $html);
        self::assertStringContainsString('value="100" data-range-progress="100" style="--iptv-range-progress:100%" data-iptv-native-volume', $html);
        self::assertStringContainsString('data-iptv-native-controls hidden', $html);
        self::assertMatchesRegularExpression('/data-iptv-native-playback[^>]*><svg data-player-icon="pause"[^>]*><use href="#iptv-icon-pause"><\/use><\/svg><\/button>/', $html);
        self::assertMatchesRegularExpression('/data-iptv-native-mute[^>]*><svg data-player-icon="volume"[^>]*><use href="#iptv-icon-volume"><\/use><\/svg><\/button>/', $html);
        self::assertStringContainsString('data-iptv-view-tabs role="tablist"', $html);
        self::assertStringContainsString('data-iptv-view="favorites" role="tab"', $html);
        self::assertStringContainsString('data-iptv-view="recents" role="tab"', $html);
        self::assertStringContainsString('data-iptv-view="all" role="tab" aria-selected="true"', $html);
        self::assertStringContainsString('>Todos</button>', $html);
        self::assertStringContainsString('data-iptv-native-progress aria-label="Posição do vídeo"', $html);
        self::assertStringContainsString('data-iptv-native-fullscreen', $html);
        self::assertStringContainsString('data-iptv-native-vod hidden', $html);
        self::assertStringContainsString('aria-label="Voltar 30 segundos" title="Voltar 30 segundos"', $html);
        self::assertStringContainsString('aria-label="Avançar 30 segundos" title="Avançar 30 segundos"', $html);
        self::assertStringContainsString('Não foi possível reproduzir este conteúdo.', $html);
        self::assertStringContainsString('Escolher outro</button>', $html);
        self::assertStringContainsString('role="tabpanel"', $html);
        self::assertStringContainsString('id="iptv-icon-heart"', $html);
        self::assertStringContainsString('data-settings-state="idle"', $html);
        self::assertStringContainsString('data-iptv-settings-content', $html);
        self::assertStringContainsString('data-iptv-settings-loader role="status" aria-live="polite" hidden', $html);
        self::assertStringContainsString('Adicionar arquivo M3U', $html);
        self::assertStringNotContainsString('>Arquivo M3U</button>', $html);
        self::assertStringNotContainsString('hls.min.js', $html);
        self::assertStringNotContainsString('<video', $html);
        self::assertStringNotContainsString('data-iptv-autoplay', $html);
        self::assertStringContainsString('aria-live="polite"', $html);
        self::assertStringNotContainsString('streamUrl', $html);
        self::assertStringNotContainsString('stable_key', $html);
        self::assertStringNotContainsString('logical_key', $html);
        self::assertStringContainsString('data-iptv-primary-panel="live"', $html);
        self::assertStringContainsString('data-iptv-primary-panel="movies"', $html);
        self::assertStringContainsString('data-iptv-primary-panel="series"', $html);
        self::assertMatchesRegularExpression('/data-iptv-primary-panel="movies"[^>]*hidden/', $html);
        self::assertMatchesRegularExpression('/data-iptv-primary-panel="series"[^>]*hidden/', $html);
    }

    public function testHiddenRuleWinsOverGridAndFlexContainersInsideIptvExperience(): void
    {
        $css = file_get_contents(dirname(__DIR__) . '/public/assets/css/desktop-iptv.css');

        self::assertIsString($css);
        self::assertStringContainsString('.iptv-experience [hidden] { display: none !important; }', $css);
    }
}
