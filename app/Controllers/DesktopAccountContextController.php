<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Response;
use App\Repositories\UserRepository;
use App\Services\AuthSession;
use Throwable;

final class DesktopAccountContextController
{
    private const HEADERS = ['Cache-Control' => 'no-store', 'Pragma' => 'no-cache', 'X-Content-Type-Options' => 'nosniff'];

    public function __construct(private readonly UserRepository $users, private readonly AuthSession $auth)
    {
    }

    public function show(): Response
    {
        $userId = $this->auth->userId();
        if ($userId === null || $this->users->findById($userId) === null) {
            if ($userId !== null) {
                $this->auth->logout();
            }
            return Response::json(['error' => 'authentication_required'], 401, self::HEADERS);
        }
        try {
            $profileId = $this->users->ensureDesktopProfileId($userId);
        } catch (Throwable) {
            return Response::json(['error' => 'account_context_unavailable'], 503, self::HEADERS);
        }
        if ($profileId === null) {
            return Response::json(['error' => 'authentication_required'], 401, self::HEADERS);
        }
        return Response::json(['profile_id' => $profileId], 200, self::HEADERS);
    }
}
