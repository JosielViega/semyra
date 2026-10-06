<?php

declare(strict_types=1);

namespace Tests\Support;

use PDO;
use PDOStatement;

final class DesktopHostSessionPdo extends PDO
{
    /** @var array<string, array<string, mixed>> */
    public array $sessions = [];
    /** @var null|array<string, mixed> */
    public ?array $transmission = null;
    /** @var array<int, array<string, mixed>> */
    public array $users = [];
    public bool $roomExists = true;
    public bool $changeBeforeHostInsert = false;
    private int $nextId = 1;

    public function __construct()
    {
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        return new DesktopHostSessionStatement($this, $query);
    }

    /** @param array<string, mixed> $params */
    public function insertSession(array $params): bool
    {
        if ($this->changeBeforeHostInsert && $this->transmission !== null) {
            $this->transmission['instance_id'] = str_repeat('f', 32);
            $this->changeBeforeHostInsert = false;
        }
        $current = $this->transmission;
        if ($current === null
            || (int) $current['room_id'] !== (int) $params['room_id']
            || $current['instance_id'] !== $params['transmission_instance_id']
            || (int) $current['revision'] !== (int) $params['transmission_revision']
            || $current['source_type'] !== 'iptv'
            || $current['media_mode'] !== 'live') {
            return false;
        }
        $ownerMatches = $current['owner_user_id'] !== null
            ? (int) $current['owner_user_id'] === (int) $params['owner_user_id']
            : $current['owner_participant_key_hash'] === $params['owner_participant_key_hash'];
        if (!$ownerMatches) {
            return false;
        }

        $selector = (string) $params['selector'];
        $this->sessions[$selector] = [
            'id' => $this->nextId++,
            'room_id' => (int) $current['room_id'],
            'transmission_instance_id' => (string) $current['instance_id'],
            'transmission_revision' => (int) $current['revision'],
            'owner_user_id' => $current['owner_user_id'],
            'owner_participant_key_hash' => (string) $current['owner_participant_key_hash'],
            'permission' => (string) $params['permission'],
            'selector' => $selector,
            'validator_hash' => (string) $params['validator_hash'],
            'expires_at' => (string) $params['expires_at'],
            'created_at' => '2026-10-06 12:00:00.000',
            'revoked_at' => null,
            'expired' => false,
        ];
        return true;
    }

    public function seedUser(int $id): void
    {
        $this->users[$id] = [
            'id' => $id,
            'display_name' => 'Account Owner',
            'email' => 'owner@example.test',
            'password_hash' => 'not-used',
            'created_at' => '2026-10-06 12:00:00.000',
            'updated_at' => '2026-10-06 12:00:00.000',
        ];
    }
}

final class DesktopHostSessionStatement extends PDOStatement
{
    private array $params = [];
    private int $affected = 0;

    public function __construct(
        private readonly DesktopHostSessionPdo $pdo,
        private readonly string $query,
    ) {
    }

    public function execute(?array $params = null): bool
    {
        $this->params = $params ?? [];
        $this->affected = 0;

        if (str_starts_with($this->query, 'INSERT INTO desktop_host_sessions')) {
            $this->affected = $this->pdo->insertSession($this->params) ? 1 : 0;
        } elseif (str_starts_with($this->query, 'UPDATE desktop_host_sessions')) {
            foreach ($this->pdo->sessions as $selector => $session) {
                $matches = str_contains($this->query, 'WHERE selector =')
                    ? $selector === ($this->params['selector'] ?? null)
                    : (int) $session['room_id'] === (int) $this->params['room_id']
                        && $session['transmission_instance_id'] === $this->params['transmission_instance_id']
                        && (int) $session['transmission_revision'] === (int) $this->params['transmission_revision']
                        && $session['permission'] === $this->params['permission']
                        && ($session['owner_user_id'] !== null
                            ? (int) $session['owner_user_id'] === (int) $this->params['owner_user_id']
                            : $session['owner_participant_key_hash'] === $this->params['owner_participant_key_hash']);
                if ($matches && $session['revoked_at'] === null) {
                    $this->pdo->sessions[$selector]['revoked_at'] = '2026-10-06 12:01:00.000';
                    ++$this->affected;
                }
            }
        } elseif (str_starts_with($this->query, 'DELETE FROM desktop_host_sessions')) {
            foreach ($this->pdo->sessions as $selector => $session) {
                if ($session['expired']) {
                    unset($this->pdo->sessions[$selector]);
                    ++$this->affected;
                }
            }
        }

        return true;
    }

    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed
    {
        if (str_contains($this->query, 'FROM desktop_host_sessions')) {
            return $this->pdo->sessions[(string) ($this->params['selector'] ?? '')] ?? false;
        }
        if (str_contains($this->query, 'FROM rooms')) {
            return $this->pdo->roomExists ? [
                'id' => 7,
                'code' => 'ROOM2345',
                'created_by_user_id' => null,
                'last_activity_at' => '2026-10-06 12:00:00.000',
                'created_at' => '2026-10-06 12:00:00.000',
            ] : false;
        }
        if (str_contains($this->query, 'FROM room_transmissions transmission')) {
            return $this->pdo->transmission ?? false;
        }
        if (str_contains($this->query, 'FROM users')) {
            return $this->pdo->users[(int) ($this->params['id'] ?? 0)] ?? false;
        }

        return false;
    }

    public function rowCount(): int
    {
        return $this->affected;
    }
}
