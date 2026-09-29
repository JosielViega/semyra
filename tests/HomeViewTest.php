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
            'currentUser' => null,
        ]);

        self::assertStringContainsString('action="/rooms"', $html);
        self::assertStringContainsString('Criar sala', $html);
        self::assertStringContainsString('name="_token" value="csrf-token"', $html);
        self::assertStringContainsString('/assets/css/tokens.css?v=8b-identity', $html);
        self::assertStringContainsString('/assets/css/app.css?v=rooms-1', $html);
        self::assertStringContainsString('/assets/images/logo_semyra_symbol.png?v=8b-polish-2', $html);
        self::assertStringContainsString('<strong>SEMYRA</strong>', $html);
        self::assertStringContainsString('tempo real', $html);
        self::assertStringContainsString('Assista com amigos', $html);
        self::assertStringContainsString('via YouTube', $html);
        self::assertStringContainsString('Simples e r', $html);
        self::assertStringNotContainsString('youtube_url', $html);
        self::assertStringNotContainsString('URL do vídeo', $html);
        self::assertStringContainsString('href="/login"', $html);
        self::assertStringContainsString('href="/register"', $html);
        self::assertStringContainsString('Salas criadas sem conta expiram após 24 horas sem atividade.', $html);
        self::assertStringNotContainsString('Minhas Salas', $html);
    }

    public function testAuthenticatedHomeShowsNameAndPostLogoutWithoutFutureLinks(): void
    {
        $view = new View(dirname(__DIR__) . '/resources/views');
        $html = $view->render('pages/home', [
            'title' => 'Semyra',
            'appName' => 'Semyra',
            'csrfField' => '<input type="hidden" name="_token" value="csrf-token">',
            'flashes' => [],
            'currentUser' => ['id' => 4, 'display_name' => 'Josiel', 'email' => 'josiel@example.com'],
        ]);

        self::assertStringContainsString('Olá, Josiel', $html);
        self::assertStringContainsString('<form class="account-logout" method="post" action="/logout">', $html);
        self::assertStringContainsString('name="_token" value="csrf-token"', $html);
        self::assertStringContainsString('>Sair</button>', $html);
        self::assertStringContainsString('As salas que você criar conectado ficam salvas.', $html);
        self::assertStringNotContainsString('Minhas Salas', $html);
    }
}
