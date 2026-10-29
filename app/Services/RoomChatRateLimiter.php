<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Session;

final class RoomChatRateLimiter
{
    private const SESSION_KEY = 'room_chat_rate_limits';
    private const WINDOW_SECONDS = 10;
    private const MAX_MESSAGES = 6;
    private const DUPLICATE_SECONDS = 2;

    public function __construct(private readonly Session $session)
    {
    }

    public function allow(string $roomCode, string $participantKeyHash, string $message, ?int $now = null): bool
    {
        $now ??= time();
        $all = $this->session->get(self::SESSION_KEY, []);
        if (!is_array($all)) {
            $all = [];
        }
        $key = hash('sha256', $roomCode . "\0" . $participantKeyHash);
        $events = is_array($all[$key]['events'] ?? null) ? $all[$key]['events'] : [];
        $events = array_values(array_filter(
            $events,
            static fn (mixed $timestamp): bool => is_int($timestamp) && $timestamp > $now - self::WINDOW_SECONDS,
        ));
        $messageHash = hash('sha256', $message);
        $lastHash = is_string($all[$key]['last_hash'] ?? null) ? $all[$key]['last_hash'] : '';
        $lastAt = is_int($all[$key]['last_at'] ?? null) ? $all[$key]['last_at'] : 0;
        if (count($events) >= self::MAX_MESSAGES
            || ($lastHash !== '' && hash_equals($lastHash, $messageHash) && $lastAt > $now - self::DUPLICATE_SECONDS)) {
            return false;
        }
        $events[] = $now;
        $all[$key] = ['events' => $events, 'last_hash' => $messageHash, 'last_at' => $now];
        if (count($all) > 24) {
            $all = array_slice($all, -24, null, true);
        }
        $this->session->put(self::SESSION_KEY, $all);

        return true;
    }
}
