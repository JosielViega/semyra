<?php

declare(strict_types=1);

namespace Tests;

use App\Core\Session;
use App\Services\AuthSession;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

final class AuthSessionTest extends TestCase
{
    protected function setUp(): void
    {
        $_SESSION = [];
    }

    public function testStartsLoggedOutAndLoginStoresOnlyAuthUser(): void
    {
        $_SESSION['room_participants'] = ['ROOM1234' => ['display_name' => 'Convidado']];
        $auth = new AuthSession(new Session(false));

        self::assertNull($auth->userId());
        $auth->login(42);

        self::assertSame(42, $auth->userId());
        self::assertSame('Convidado', $_SESSION['room_participants']['ROOM1234']['display_name']);
    }

    public function testLogoutRemovesOnlyAuthenticatedUser(): void
    {
        $_SESSION = [
            'auth_user_id' => 42,
            'room_participants' => ['ROOM1234' => ['participant_key' => str_repeat('a', 64)]],
            '_csrf_token' => str_repeat('b', 64),
        ];
        $auth = new AuthSession(new Session(false));

        $auth->logout();

        self::assertNull($auth->userId());
        self::assertArrayHasKey('room_participants', $_SESSION);
        self::assertArrayHasKey('_csrf_token', $_SESSION);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testLoginAndLogoutRegenerateSessionIdWhilePreservingRoomIdentity(): void
    {
        $_SESSION = [];
        $session = new Session();
        $session->start([
            'use_cookies' => false,
            'cache_limiter' => '',
        ]);
        $_SESSION['room_participants'] = ['ROOM1234' => [
            'participant_key' => str_repeat('c', 64),
            'display_name' => 'Pedro',
        ]];
        $auth = new AuthSession($session);
        $beforeLogin = session_id();

        $auth->login(7);
        $afterLogin = session_id();
        $auth->logout();
        $afterLogout = session_id();

        self::assertNotSame($beforeLogin, $afterLogin);
        self::assertNotSame($afterLogin, $afterLogout);
        self::assertSame('Pedro', $_SESSION['room_participants']['ROOM1234']['display_name']);
        self::assertNull($auth->userId());
        session_write_close();
    }
}
