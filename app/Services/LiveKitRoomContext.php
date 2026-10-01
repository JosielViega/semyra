<?php

declare(strict_types=1);

namespace App\Services;

final class LiveKitRoomContext
{
    public function __construct(private readonly string $namespace)
    {
        if (trim($namespace) === '') {
            throw new \InvalidArgumentException('LiveKit namespace must not be empty.');
        }
    }

    public function roomName(int $roomId): string
    {
        $this->assertPositive($roomId, 'Room ID');

        return 'smy_r_' . $this->digest('room:' . $roomId);
    }

    public function publisherIdentity(int $roomId, int $transmissionRevision, string $startedAt): string
    {
        $this->assertPositive($roomId, 'Room ID');
        $this->assertPositive($transmissionRevision, 'Transmission revision');
        $normalizedStartedAt = $this->normalizeStartedAt($startedAt);

        return 'smy_i_' . $this->digest(
            'publisher:' . $roomId . ':' . $transmissionRevision . ':' . $normalizedStartedAt,
        );
    }

    public function viewerIdentity(): string
    {
        return 'smy_v_' . bin2hex(random_bytes(24));
    }

    private function digest(string $value): string
    {
        return substr(hash('sha256', "semyra-livekit-v1\0" . $this->namespace . "\0" . $value), 0, 32);
    }

    private function assertPositive(int $value, string $label): void
    {
        if ($value < 1) {
            throw new \InvalidArgumentException($label . ' must be positive.');
        }
    }

    private function normalizeStartedAt(string $startedAt): string
    {
        $value = trim($startedAt);
        if (preg_match(
            '/^(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})(?:\.(\d{1,6}))?$/',
            $value,
            $matches,
        ) !== 1) {
            throw new \InvalidArgumentException('Transmission start timestamp is invalid.');
        }

        $canonical = $matches[1] . '.' . str_pad($matches[2] ?? '', 6, '0');
        $timestamp = \DateTimeImmutable::createFromFormat(
            '!Y-m-d H:i:s.u',
            $canonical,
            new \DateTimeZone('UTC'),
        );
        $errors = \DateTimeImmutable::getLastErrors();
        if ($timestamp === false
            || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
            || $timestamp->format('Y-m-d H:i:s.u') !== $canonical) {
            throw new \InvalidArgumentException('Transmission start timestamp is invalid.');
        }

        return $canonical;
    }
}
