<?php

declare(strict_types=1);

namespace Semyra\BridgeWorker;

final class MediaExitStatus
{
    public const SOURCE_EXITED = 'source_exited';
    public const PIPELINE_EXITED = 'pipeline_exited';
    public const SOURCE_AND_PIPELINE_EXITED = 'source_and_pipeline_exited';
    public const HELPER_FAILED = 'helper_failed';

    private const ALLOWED = [
        self::SOURCE_EXITED,
        self::PIPELINE_EXITED,
        self::SOURCE_AND_PIPELINE_EXITED,
        self::HELPER_FAILED,
    ];

    public static function classify(bool $sourceRunning, bool $pipelineRunning): string
    {
        if (!$sourceRunning && $pipelineRunning) {
            return self::SOURCE_EXITED;
        }
        if ($sourceRunning && !$pipelineRunning) {
            return self::PIPELINE_EXITED;
        }
        if (!$sourceRunning && !$pipelineRunning) {
            return self::SOURCE_AND_PIPELINE_EXITED;
        }

        return self::HELPER_FAILED;
    }

    public static function write(string $path, string $reason): bool
    {
        return $path !== ''
            && in_array($reason, self::ALLOWED, true)
            && @file_put_contents($path, $reason, LOCK_EX) !== false;
    }

    public static function read(string $path): string
    {
        $reason = is_file($path) ? trim((string) @file_get_contents($path)) : '';

        return in_array($reason, self::ALLOWED, true) ? $reason : self::HELPER_FAILED;
    }

    public static function errorCode(string $reason): string
    {
        return $reason === self::SOURCE_EXITED ? 'source_failed' : 'pipeline_failed';
    }
}
