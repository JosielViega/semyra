<?php

declare(strict_types=1);

namespace App\Core;

final class Csrf
{
    private const SESSION_KEY = '_csrf_token';

    public function __construct(private readonly Session $session)
    {
    }

    public function token(): string
    {
        $token = $this->session->get(self::SESSION_KEY);

        if (!is_string($token) || strlen($token) < 32) {
            $token = bin2hex(random_bytes(32));
            $this->session->put(self::SESSION_KEY, $token);
        }

        return $token;
    }

    public function field(): string
    {
        return '<input type="hidden" name="_token" value="' . e($this->token()) . '">';
    }

    public function verify(mixed $submittedToken): bool
    {
        $storedToken = $this->session->get(self::SESSION_KEY);

        return is_string($storedToken)
            && is_string($submittedToken)
            && hash_equals($storedToken, $submittedToken);
    }
}
