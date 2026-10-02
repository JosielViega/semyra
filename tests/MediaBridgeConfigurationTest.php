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
        self::assertStringContainsString('MEDIA_BRIDGE_MAX_ATTEMPTS=3', $example);
        self::assertDoesNotMatchRegularExpression('/MEDIA_BRIDGE_WORKER_SECRET=[a-f0-9]{64}/', $example);
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
}
