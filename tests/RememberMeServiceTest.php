<?php

declare(strict_types=1);

namespace Tests;

use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Repositories\UserRememberTokenRepository;
use App\Repositories\UserRepository;
use App\Services\AuthSession;
use App\Services\RememberMeService;
use PHPUnit\Framework\TestCase;
use Tests\Support\RememberTokenPdo;

final class RememberMeServiceTest extends TestCase
{
    protected function setUp(): void
    {
        $_SESSION = [];
    }

    public function testIssueStoresOnlyHashAndCreatesHardenedCookie(): void
    {
        [$service, $pdo] = $this->service(secure: true);

        $service->issue(1);
        $header = $service->applyTo(Response::html(''))->headers()['Set-Cookie'];
        $cookie = $this->cookieValue($header);
        [$selector, $validator] = explode('.', $cookie, 2);

        self::assertMatchesRegularExpression('/\A[a-f0-9]{32}\z/', $selector);
        self::assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', $validator);
        self::assertSame(hash('sha256', $validator), $pdo->tokens[$selector]['validator_hash']);
        self::assertNotSame($validator, $pdo->tokens[$selector]['validator_hash']);
        self::assertStringContainsString('Max-Age=2592000', $header);
        self::assertStringContainsString('Path=/', $header);
        self::assertStringContainsString('HttpOnly', $header);
        self::assertStringContainsString('SameSite=Lax', $header);
        self::assertStringContainsString('Secure', $header);
    }

    public function testValidCookieRestoresSessionRotatesValidatorAndRejectsOldCookie(): void
    {
        [$issuer, $pdo] = $this->service();
        $issuer->issue(1);
        $oldCookie = $this->cookieValue($issuer->applyTo(Response::html(''))->headers()['Set-Cookie']);
        [$selector, $oldValidator] = explode('.', $oldCookie, 2);

        $_SESSION = [];
        [$restorer, , $auth] = $this->service($oldCookie, pdo: $pdo);
        self::assertTrue($restorer->restore());
        self::assertSame(1, $auth->userId());
        $newCookie = $this->cookieValue($restorer->applyTo(Response::html(''))->headers()['Set-Cookie']);
        [$newSelector, $newValidator] = explode('.', $newCookie, 2);
        self::assertSame($selector, $newSelector);
        self::assertNotSame($oldValidator, $newValidator);
        self::assertSame(hash('sha256', $newValidator), $pdo->tokens[$selector]['validator_hash']);

        $_SESSION = [];
        [$replay, , $replayAuth] = $this->service($oldCookie, pdo: $pdo);
        self::assertFalse($replay->restore());
        self::assertNull($replayAuth->userId());
        self::assertArrayNotHasKey($selector, $pdo->tokens);
        self::assertStringContainsString('Max-Age=0', $replay->applyTo(Response::html(''))->headers()['Set-Cookie']);
    }

    public function testMalformedAndUnknownCookiesAreClearedWithoutAuthentication(): void
    {
        foreach (['malformed', str_repeat('a', 32) . '.' . str_repeat('b', 64)] as $cookie) {
            $_SESSION = [];
            [$service, , $auth] = $this->service($cookie);
            self::assertFalse($service->restore());
            self::assertNull($auth->userId());
            self::assertStringContainsString(
                'Max-Age=0',
                $service->applyTo(Response::html(''))->headers()['Set-Cookie'],
            );
        }
    }

    public function testWrongValidatorExpiredTokenAndMissingUserAreRevoked(): void
    {
        foreach (['wrong', 'expired', 'missing-user'] as $scenario) {
            $_SESSION = [];
            $pdo = new RememberTokenPdo();
            if ($scenario !== 'missing-user') {
                $pdo->seedUser();
            }
            $selector = str_repeat(match ($scenario) {
                'wrong' => 'c',
                'expired' => 'd',
                default => 'e',
            }, 32);
            $validator = str_repeat('f', 64);
            [$service, , $auth, $repository] = $this->service(
                $selector . '.' . ($scenario === 'wrong' ? str_repeat('0', 64) : $validator),
                pdo: $pdo,
                seedUser: false,
            );
            $repository->create(1, $selector, hash('sha256', $validator), 30);
            if ($scenario === 'expired') {
                $pdo->expire($selector);
            }

            self::assertFalse($service->restore(), $scenario);
            self::assertNull($auth->userId(), $scenario);
            self::assertArrayNotHasKey($selector, $pdo->tokens, $scenario);
            self::assertStringContainsString(
                'Max-Age=0',
                $service->applyTo(Response::html(''))->headers()['Set-Cookie'],
                $scenario,
            );
        }
    }

    public function testRevokeCurrentDeletesOnlyMatchingTokenAndClearsCookie(): void
    {
        [$issuer, $pdo] = $this->service();
        $issuer->issue(1);
        $cookie = $this->cookieValue($issuer->applyTo(Response::html(''))->headers()['Set-Cookie']);
        [$selector] = explode('.', $cookie, 2);

        [$service] = $this->service($cookie, pdo: $pdo);
        $service->revokeCurrent();

        self::assertArrayNotHasKey($selector, $pdo->tokens);
        self::assertStringContainsString('Max-Age=0', $service->applyTo(Response::html(''))->headers()['Set-Cookie']);
    }

    public function testExistingValidSessionWinsAndInvalidSessionCanBeRestored(): void
    {
        [$issuer, $pdo] = $this->service();
        $issuer->issue(1);
        $cookie = $this->cookieValue($issuer->applyTo(Response::html(''))->headers()['Set-Cookie']);
        [$selector] = explode('.', $cookie, 2);
        $originalHash = $pdo->tokens[$selector]['validator_hash'];

        $_SESSION = [];
        [$validSession, , $validAuth] = $this->service($cookie, pdo: $pdo);
        $validAuth->login(1);
        self::assertFalse($validSession->restore());
        self::assertSame($originalHash, $pdo->tokens[$selector]['validator_hash']);

        $_SESSION = [];
        [$invalidSession, , $invalidAuth] = $this->service($cookie, pdo: $pdo);
        $invalidAuth->login(99);
        self::assertTrue($invalidSession->restore());
        self::assertSame(1, $invalidAuth->userId());
        self::assertNotSame($originalHash, $pdo->tokens[$selector]['validator_hash']);
    }

    /** @return array{RememberMeService, RememberTokenPdo, AuthSession, UserRememberTokenRepository} */
    private function service(
        ?string $cookie = null,
        bool $secure = false,
        ?RememberTokenPdo $pdo = null,
        bool $seedUser = true,
    ): array {
        $pdo ??= new RememberTokenPdo();
        if ($seedUser && !isset($pdo->users[1])) {
            $pdo->seedUser();
        }
        $database = new Database([]);
        (new \ReflectionProperty($database, 'connection'))->setValue($database, $pdo);
        $auth = new AuthSession(new Session(false));
        $repository = new UserRememberTokenRepository($database);
        $request = new Request(cookies: $cookie === null ? [] : [RememberMeService::COOKIE_NAME => $cookie]);
        $service = new RememberMeService(
            $request,
            $repository,
            new UserRepository($database),
            $auth,
            30,
            $secure,
        );

        return [$service, $pdo, $auth, $repository];
    }

    private function cookieValue(string $header): string
    {
        self::assertMatchesRegularExpression('/\Asemyra_remember=[^;]+;/', $header);

        return explode(';', substr($header, strlen('semyra_remember=')), 2)[0];
    }
}
