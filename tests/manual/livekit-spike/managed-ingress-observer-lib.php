<?php

declare(strict_types=1);

namespace Semyra\ManagedIngressObserver;

use InvalidArgumentException;

final class TransitionRecorder
{
    private ?string $lastFingerprint = null;

    /** @param array<string, mixed> $sample */
    public function record(float $elapsedSeconds, array $sample): ?string
    {
        $safe = self::sanitize($sample);
        $fingerprint = json_encode($safe, JSON_THROW_ON_ERROR);
        if ($fingerprint === $this->lastFingerprint) {
            return null;
        }
        $this->lastFingerprint = $fingerprint;

        $parts = [sprintf('T+%.1f', max(0.0, $elapsedSeconds)), 'status=' . $safe['status']];
        foreach (['video', 'audio', 'width', 'height', 'fps'] as $field) {
            if ($safe[$field] !== null) {
                $parts[] = $field . '=' . $safe[$field];
            }
        }

        return implode(' ', $parts);
    }

    /** @param array<string, mixed> $sample
     *  @return array{status: string, video: ?string, audio: ?string, width: ?int, height: ?int, fps: ?float}
     */
    private static function sanitize(array $sample): array
    {
        $status = (string) ($sample['status'] ?? '');
        if (!in_array($status, ['inactive', 'buffering', 'publishing', 'error', 'complete'], true)) {
            throw new InvalidArgumentException('Ingress observer status is invalid.');
        }

        $video = strtolower((string) ($sample['video_mime'] ?? '')) === 'video/h264' ? 'H264' : null;
        $audio = strtolower((string) ($sample['audio_mime'] ?? '')) === 'audio/opus' ? 'Opus' : null;
        $width = filter_var($sample['width'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null;
        $height = filter_var($sample['height'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null;
        $fpsValue = is_numeric($sample['fps'] ?? null) ? (float) $sample['fps'] : 0.0;
        $fps = $fpsValue > 0 ? round($fpsValue, 2) : null;

        return compact('status', 'video', 'audio', 'width', 'height', 'fps');
    }
}
