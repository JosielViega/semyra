<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Session;

final class AuthSession
{
    private const USER_ID_KEY = 'auth_user_id';

    public function __construct(private readonly Session $session)
    {
    }

    public function userId(): ?int
    {
        $userId = $this->session->get(self::USER_ID_KEY);

        return is_int($userId) && $userId > 0 ? $userId : null;
    }

    public function login(int $userId): void
    {
        if ($userId <= 0) {
            throw new \InvalidArgumentException('User ID must be positive.');
        }

        $this->session->regenerate();
        $this->session->put(self::USER_ID_KEY, $userId);
    }

    public function logout(): void
    {
        $this->session->forget(self::USER_ID_KEY);
        $this->session->regenerate();
    }
}
