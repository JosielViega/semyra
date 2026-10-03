<?php

declare(strict_types=1);

namespace Semyra\BridgeWorker;

final class WorkerConfig
{
    public const IMAGE = 'livekit/gstreamer:1.22.8-prod-rs';
    public const IMAGE_DIGEST = 'sha256:0d9663ca1b0c13b752558b241494a7df61c6e1775ec37b455c76e3eb43b8e4f1';

    public function __construct(
        public readonly string $environment,
        public readonly string $controlUrl,
        public readonly string $workerId,
        public readonly string $workerSecret,
        public readonly string $catalogPath,
        public readonly int $heartbeatSeconds,
        public readonly int $pollSeconds,
        public readonly string $dockerBinary,
        public readonly string $gstreamerImage,
        public readonly string $runtimePath,
    ) {
    }

    /** @param array<string, string|false> $environment */
    public static function fromEnvironment(array $environment, string $workerRoot, bool $requireMedia): self
    {
        $appEnvironment = self::value($environment, 'APP_ENV', 'production');
        $controlUrl = rtrim(self::value($environment, 'SEMYRA_CONTROL_URL'), '/');
        $workerId = self::value($environment, 'SEMYRA_WORKER_ID');
        $workerSecret = self::value($environment, 'SEMYRA_WORKER_SECRET');
        $catalogPath = self::value($environment, 'SEMYRA_SOURCE_CATALOG_PATH');
        $heartbeatSeconds = self::integer($environment, 'SEMYRA_HEARTBEAT_SECONDS', 5);
        $pollSeconds = self::integer($environment, 'SEMYRA_POLL_SECONDS', 2);
        $dockerBinary = self::value($environment, 'SEMYRA_DOCKER_BIN', 'docker');
        $image = self::value($environment, 'SEMYRA_GSTREAMER_IMAGE', $requireMedia ? '' : self::IMAGE);

        $parts = parse_url($controlUrl);
        $localHttp = in_array($appEnvironment, ['local', 'testing'], true)
            && ($parts['scheme'] ?? '') === 'http'
            && in_array(strtolower((string) ($parts['host'] ?? '')), ['127.0.0.1', 'localhost'], true);
        if ((($parts['scheme'] ?? '') !== 'https' && !$localHttp)
            || preg_match('/^wrk_[a-f0-9]{32}$/', $workerId) !== 1
            || preg_match('/^[a-f0-9]{64}$/', $workerSecret) !== 1
            || $heartbeatSeconds < 1 || $pollSeconds < 1
            || preg_match('#^[A-Za-z0-9._:/\\\\-]+$#', $dockerBinary) !== 1) {
            throw new WorkerException('invalid_configuration');
        }
        if ($requireMedia && ($catalogPath === '' || !is_file($catalogPath) || $image !== self::IMAGE)) {
            throw new WorkerException('invalid_configuration');
        }

        return new self(
            $appEnvironment,
            $controlUrl,
            $workerId,
            $workerSecret,
            $catalogPath,
            $heartbeatSeconds,
            $pollSeconds,
            $dockerBinary,
            $image,
            $workerRoot . DIRECTORY_SEPARATOR . 'runtime',
        );
    }

    /** @param array<string, string|false> $environment */
    private static function value(array $environment, string $key, string $default = ''): string
    {
        $value = $environment[$key] ?? $default;
        return is_string($value) ? trim($value) : $default;
    }

    /** @param array<string, string|false> $environment */
    private static function integer(array $environment, string $key, int $default): int
    {
        $value = self::value($environment, $key, (string) $default);
        return preg_match('/^[0-9]+$/', $value) === 1 ? (int) $value : -1;
    }
}
