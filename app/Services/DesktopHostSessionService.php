<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\DesktopHostSessionRepository;
use DateTimeImmutable;
use DateTimeZone;

final class DesktopHostSessionService
{
    public const PERMISSION = 'media.publish';
    private const TOKEN_PATTERN = '/\A([a-f0-9]{32})\.([a-f0-9]{64})\z/D';

    public function __construct(
        private readonly DesktopHostSessionRepository $sessions,
        private readonly int $ttlSeconds,
    ) {
        if ($this->ttlSeconds < 60 || $this->ttlSeconds > 3600) {
            throw new \InvalidArgumentException('Desktop Host Session TTL must be between 60 and 3600 seconds.');
        }
    }

    /** @return null|array{token: string, expires_at: string, permission: string} */
    public function issue(
        int $roomId,
        string $instanceId,
        int $revision,
        ?int $ownerUserId,
        string $ownerParticipantKeyHash,
    ): ?array {
        $this->sessions->deleteExpired();
        $this->sessions->revokeEquivalent(
            $roomId,
            $instanceId,
            $revision,
            $ownerUserId,
            $ownerParticipantKeyHash,
            self::PERMISSION,
        );

        $selector = bin2hex(random_bytes(16));
        $validator = bin2hex(random_bytes(32));
        $expires = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->modify('+' . $this->ttlSeconds . ' seconds');
        if (!$this->sessions->create(
            $roomId,
            $instanceId,
            $revision,
            $ownerUserId,
            $ownerParticipantKeyHash,
            self::PERMISSION,
            $selector,
            hash('sha256', $validator),
            $expires->format('Y-m-d H:i:s.v'),
        )) {
            return null;
        }

        return [
            'token' => $selector . '.' . $validator,
            'expires_at' => $expires->format('Y-m-d\TH:i:s.v\Z'),
            'permission' => self::PERMISSION,
        ];
    }

    /** @return null|array{room_id: int, transmission_instance_id: string, transmission_revision: int, owner_user_id: null|int, owner_participant_key_hash: string, permission: string, expires_at: string} */
    public function validate(
        string $token,
        int $roomId,
        string $instanceId,
        int $revision,
        string $permission,
    ): ?array {
        if (preg_match(self::TOKEN_PATTERN, $token, $matches) !== 1) {
            return null;
        }

        $session = $this->sessions->findBySelector($matches[1]);
        if ($session === null
            || ($session['revoked_at'] ?? null) !== null
            || (bool) ($session['expired'] ?? true)
            || !hash_equals((string) $session['validator_hash'], hash('sha256', $matches[2]))
            || (int) $session['room_id'] !== $roomId
            || !hash_equals((string) $session['transmission_instance_id'], $instanceId)
            || (int) $session['transmission_revision'] !== $revision
            || !hash_equals((string) $session['permission'], $permission)) {
            return null;
        }

        return [
            'room_id' => (int) $session['room_id'],
            'transmission_instance_id' => (string) $session['transmission_instance_id'],
            'transmission_revision' => (int) $session['transmission_revision'],
            'owner_user_id' => $session['owner_user_id'] === null ? null : (int) $session['owner_user_id'],
            'owner_participant_key_hash' => (string) $session['owner_participant_key_hash'],
            'permission' => (string) $session['permission'],
            'expires_at' => (string) $session['expires_at'],
        ];
    }
}
