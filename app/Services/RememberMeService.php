<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Request;
use App\Core\Response;
use App\Repositories\UserRememberTokenRepository;
use App\Repositories\UserRepository;

final class RememberMeService
{
    public const COOKIE_NAME = 'semyra_remember';
    private const SELECTOR_BYTES = 16;
    private const VALIDATOR_BYTES = 32;
    private const TOKEN_PATTERN = '/\A([a-f0-9]{32})\.([a-f0-9]{64})\z/D';

    private ?string $pendingCookieHeader = null;

    public function __construct(
        private readonly Request $request,
        private readonly UserRememberTokenRepository $tokens,
        private readonly UserRepository $users,
        private readonly AuthSession $auth,
        private readonly int $rememberDays,
        private readonly bool $secure,
    ) {
        if ($this->rememberDays < 1 || $this->rememberDays > 90) {
            throw new \InvalidArgumentException('Remember duration must be between 1 and 90 days.');
        }
    }

    public function issue(int $userId): void
    {
        $this->revokeCurrent();
        $this->tokens->deleteExpired();

        $selector = bin2hex(random_bytes(self::SELECTOR_BYTES));
        $validator = bin2hex(random_bytes(self::VALIDATOR_BYTES));
        $this->tokens->create(
            $userId,
            $selector,
            hash('sha256', $validator),
            $this->rememberDays,
        );
        $this->queueCookie($selector . '.' . $validator);
    }

    public function restore(): bool
    {
        $authenticatedUserId = $this->auth->userId();
        if ($authenticatedUserId !== null) {
            if ($this->users->findById($authenticatedUserId) !== null) {
                return false;
            }

            $this->auth->logout();
        }

        $parts = $this->tokenParts($this->request->cookie(self::COOKIE_NAME));
        if ($parts === null) {
            if ($this->request->cookie(self::COOKIE_NAME) !== null) {
                $this->queueClearCookie();
            }
            return false;
        }

        [$selector, $validator] = $parts;
        $token = $this->tokens->findBySelector($selector);
        if ($token === null) {
            $this->queueClearCookie();
            return false;
        }

        if ($token['expired']) {
            $this->tokens->deleteBySelector($selector);
            $this->queueClearCookie();
            return false;
        }

        $validatorHash = hash('sha256', $validator);
        if (!hash_equals($token['validator_hash'], $validatorHash)) {
            $this->tokens->deleteBySelector($selector);
            $this->queueClearCookie();
            return false;
        }

        if ($this->users->findById($token['user_id']) === null) {
            $this->tokens->deleteBySelector($selector);
            $this->queueClearCookie();
            return false;
        }

        $newValidator = bin2hex(random_bytes(self::VALIDATOR_BYTES));
        if (!$this->tokens->rotate(
            $selector,
            $validatorHash,
            hash('sha256', $newValidator),
            $this->rememberDays,
        )) {
            $this->queueClearCookie();
            return false;
        }

        $this->auth->login($token['user_id']);
        $this->queueCookie($selector . '.' . $newValidator);

        return true;
    }

    public function revokeCurrent(): void
    {
        $parts = $this->tokenParts($this->request->cookie(self::COOKIE_NAME));
        if ($parts !== null) {
            [$selector, $validator] = $parts;
            $token = $this->tokens->findBySelector($selector);
            if ($token !== null && hash_equals($token['validator_hash'], hash('sha256', $validator))) {
                $this->tokens->deleteBySelector($selector);
            }
        }

        if ($this->request->cookie(self::COOKIE_NAME) !== null) {
            $this->queueClearCookie();
        }
    }

    public function applyTo(Response $response): Response
    {
        return $this->pendingCookieHeader === null
            ? $response
            : $response->withHeader('Set-Cookie', $this->pendingCookieHeader);
    }

    /** @return null|array{string, string} */
    private function tokenParts(?string $cookie): ?array
    {
        if ($cookie === null || preg_match(self::TOKEN_PATTERN, $cookie, $matches) !== 1) {
            return null;
        }

        return [$matches[1], $matches[2]];
    }

    private function queueCookie(string $value): void
    {
        $maxAge = $this->rememberDays * 86400;
        $this->pendingCookieHeader = self::COOKIE_NAME . '=' . $value
            . '; Expires=' . gmdate('D, d M Y H:i:s', time() + $maxAge) . ' GMT'
            . '; Max-Age=' . $maxAge
            . '; Path=/; HttpOnly; SameSite=Lax'
            . ($this->secure ? '; Secure' : '');
    }

    private function queueClearCookie(): void
    {
        $this->pendingCookieHeader = self::COOKIE_NAME . '='
            . '; Expires=Thu, 01 Jan 1970 00:00:00 GMT'
            . '; Max-Age=0; Path=/; HttpOnly; SameSite=Lax'
            . ($this->secure ? '; Secure' : '');
    }
}
