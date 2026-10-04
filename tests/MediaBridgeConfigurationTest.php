<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;

final class MediaBridgeConfigurationTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__);
    }

    public function testEnvironmentExampleContainsSafeDisabledDefaults(): void
    {
        $example = (string) file_get_contents($this->root . '/.env.example');
        self::assertStringContainsString('MEDIA_BRIDGE_ENABLED=false', $example);
        self::assertStringContainsString('MEDIA_BRIDGE_WORKER_SECRET=', $example);
        self::assertStringContainsString('MEDIA_BRIDGE_LEASE_SECONDS=20', $example);
        self::assertStringContainsString('MEDIA_BRIDGE_MAX_FAILURES=3', $example);
        self::assertStringNotContainsString('MEDIA_BRIDGE_MAX_ATTEMPTS=', $example);
        self::assertDoesNotMatchRegularExpression('/MEDIA_BRIDGE_WORKER_SECRET=[a-f0-9]{64}/', $example);
    }

    public function testFailureBudgetConfigurationDefaultsBoundsAndPrefersNewEnvironmentName(): void
    {
        self::assertSame(3, $this->mediaBridgeConfig(null, null)['max_failures']);
        self::assertSame(1, $this->mediaBridgeConfig('0', null)['max_failures']);
        self::assertSame(20, $this->mediaBridgeConfig('99', null)['max_failures']);
        self::assertSame(4, $this->mediaBridgeConfig(null, '4')['max_failures']);
        self::assertSame(7, $this->mediaBridgeConfig('7', '2')['max_failures']);
    }

    public function testFailureBudgetMigrationAddsColumnsAndConservativeBackfill(): void
    {
        $sql = (string) file_get_contents(
            $this->root . '/database/migrations/2026_10_03_000013_add_media_bridge_failure_budget.sql',
        );
        self::assertStringContainsString('failure_count INT UNSIGNED NOT NULL DEFAULT 0', $sql);
        self::assertStringContainsString('cleanup_through_attempt INT UNSIGNED NOT NULL DEFAULT 0', $sql);
        self::assertStringContainsString("status = 'failed' AND last_error_code = 'worker_shutdown' THEN attempt_count - 1", $sql);
        self::assertStringContainsString("status IN ('claimed', 'starting', 'running') THEN attempt_count - 1", $sql);
        self::assertStringContainsString('cleanup_through_attempt = 0', $sql);
    }

    public function testMigrationDefinesInstanceBoundJobWithoutTransmissionForeignKey(): void
    {
        $sql = (string) file_get_contents(
            $this->root . '/database/migrations/2026_10_02_000012_create_media_bridge_jobs.sql',
        );
        self::assertStringContainsString('transmission_instance_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL', $sql);
        self::assertStringContainsString('UNIQUE KEY uq_media_bridge_jobs_transmission_instance', $sql);
        self::assertStringContainsString('KEY idx_media_bridge_jobs_dispatch (desired_state, status, lease_expires_at)', $sql);
        self::assertStringContainsString('room_id BIGINT UNSIGNED NOT NULL', $sql);
        self::assertStringNotContainsString('REFERENCES rooms', $sql);
        self::assertStringNotContainsString('media_bridge_jobs_room_fk', $sql);
        self::assertStringNotContainsString('REFERENCES room_transmissions', $sql);
        self::assertStringNotContainsString('ENUM(', $sql);
    }

    public function testWorkerExampleAndSourceContainNoLiveKitOrProviderSecrets(): void
    {
        $example = (string) file_get_contents($this->root . '/bridge-worker/.env.example');
        $worker = (string) file_get_contents($this->root . '/bridge-worker/worker.php');
        self::assertStringContainsString('SEMYRA_CONTROL_URL=', $example);
        self::assertStringContainsString('SEMYRA_WORKER_ID=', $example);
        self::assertStringContainsString('SEMYRA_WORKER_SECRET=', $example);
        foreach (['LIVEKIT_API_SECRET', 'LIVEKIT_API_KEY', 'WHIP_ENDPOINT=', 'PROVIDER_URL'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $example . $worker);
        }
        self::assertStringContainsString('whip credentials received: yes', $worker);
        self::assertStringNotContainsString('echo $job[\'whip_endpoint\']', $worker);
        self::assertStringNotContainsString('file_put_contents', $worker);
    }

    public function testBridgeWorkerIsExplicitlyExcludedFromHostgatorMirror(): void
    {
        $manifest = require $this->root . '/deploy/hostgator/config/deploy.php';
        self::assertContains('bridge-worker', $manifest['ignore']);
        self::assertNotContains('bridge-worker', $manifest['include']);
    }

    private function mediaBridgeConfig(?string $maxFailures, ?string $legacyMaxAttempts): array
    {
        $values = [
            'MEDIA_BRIDGE_MAX_FAILURES' => $maxFailures,
            'MEDIA_BRIDGE_MAX_ATTEMPTS' => $legacyMaxAttempts,
        ];
        $previous = [];
        foreach ($values as $key => $value) {
            $previous[$key] = [
                'env_exists' => array_key_exists($key, $_ENV),
                'env' => $_ENV[$key] ?? null,
                'server_exists' => array_key_exists($key, $_SERVER),
                'server' => $_SERVER[$key] ?? null,
                'process' => getenv($key),
            ];
            unset($_ENV[$key], $_SERVER[$key]);
            putenv($key);
            if ($value !== null) {
                $_ENV[$key] = $value;
                putenv($key . '=' . $value);
            }
        }

        try {
            return require $this->root . '/config/media_bridge.php';
        } finally {
            foreach (array_keys($values) as $key) {
                unset($_ENV[$key], $_SERVER[$key]);
                putenv($key);
                if ($previous[$key]['env_exists']) {
                    $_ENV[$key] = $previous[$key]['env'];
                }
                if ($previous[$key]['server_exists']) {
                    $_SERVER[$key] = $previous[$key]['server'];
                }
                if ($previous[$key]['process'] !== false) {
                    putenv($key . '=' . $previous[$key]['process']);
                }
            }
        }
    }
}
