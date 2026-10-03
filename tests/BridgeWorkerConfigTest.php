<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Semyra\BridgeWorker\WorkerConfig;
use Semyra\BridgeWorker\WorkerException;

require_once __DIR__ . '/../bridge-worker/bootstrap.php';

final class BridgeWorkerConfigTest extends TestCase
{
    private string $catalog;

    protected function setUp(): void
    {
        $this->catalog = tempnam(sys_get_temp_dir(), 'semyra-catalog-');
        file_put_contents($this->catalog, '{"version":1,"sources":{}}');
    }

    protected function tearDown(): void
    {
        @unlink($this->catalog);
    }

    public function testAcceptsPinnedLocalConfiguration(): void
    {
        $config = WorkerConfig::fromEnvironment($this->environment(), dirname(__DIR__) . '/bridge-worker', true);

        self::assertSame(WorkerConfig::IMAGE, $config->gstreamerImage);
        self::assertSame(5, $config->heartbeatSeconds);
    }

    #[DataProvider('leaseTimingProvider')]
    public function testLeaseSupportKeepsWatchdogInsideLease(
        int $heartbeatSeconds,
        int $leaseSeconds,
        int $expectedWatchdogAge,
        bool $expectedSupport,
    ): void {
        $environment = $this->environment();
        $environment['SEMYRA_HEARTBEAT_SECONDS'] = (string) $heartbeatSeconds;
        $config = WorkerConfig::fromEnvironment($environment, dirname(__DIR__) . '/bridge-worker', true);

        self::assertSame($expectedWatchdogAge, $config->watchdogMaximumAgeSeconds());
        self::assertSame($expectedSupport, $config->supportsLease($leaseSeconds));
        if ($expectedSupport) {
            self::assertLessThan(
                $leaseSeconds,
                $config->watchdogMaximumAgeSeconds() + $config->watchdogEnforcementSlackSeconds(),
            );
        }
    }

    public static function leaseTimingProvider(): array
    {
        return [
            'default lease' => [5, 20, 15, true],
            'one second beyond enforced boundary' => [5, 18, 15, true],
            'equal enforced boundary' => [5, 17, 15, false],
            'inside enforcement slack' => [5, 16, 15, false],
            'short heartbeat safe lease' => [1, 6, 3, true],
            'short heartbeat boundary' => [1, 5, 3, false],
        ];
    }

    #[DataProvider('invalidConfigurationProvider')]
    public function testRejectsUnsafeConfiguration(string $key, string $value): void
    {
        $environment = $this->environment();
        $environment[$key] = $value;

        $this->expectException(WorkerException::class);
        WorkerConfig::fromEnvironment($environment, dirname(__DIR__) . '/bridge-worker', true);
    }

    public static function invalidConfigurationProvider(): array
    {
        return [
            'remote plain HTTP' => ['SEMYRA_CONTROL_URL', 'http://example.com'],
            'wrong image' => ['SEMYRA_GSTREAMER_IMAGE', 'livekit/gstreamer:latest'],
            'missing image' => ['SEMYRA_GSTREAMER_IMAGE', ''],
            'missing catalog' => ['SEMYRA_SOURCE_CATALOG_PATH', 'C:/definitely/missing/source-catalog.json'],
            'invalid worker id' => ['SEMYRA_WORKER_ID', 'worker'],
            'invalid secret' => ['SEMYRA_WORKER_SECRET', 'secret'],
            'zero heartbeat' => ['SEMYRA_HEARTBEAT_SECONDS', '0'],
        ];
    }

    private function environment(): array
    {
        return [
            'APP_ENV' => 'testing',
            'SEMYRA_CONTROL_URL' => 'http://127.0.0.1:8010',
            'SEMYRA_WORKER_ID' => 'wrk_' . str_repeat('a', 32),
            'SEMYRA_WORKER_SECRET' => str_repeat('b', 64),
            'SEMYRA_SOURCE_CATALOG_PATH' => $this->catalog,
            'SEMYRA_HEARTBEAT_SECONDS' => '5',
            'SEMYRA_POLL_SECONDS' => '2',
            'SEMYRA_DOCKER_BIN' => 'docker',
            'SEMYRA_GSTREAMER_IMAGE' => WorkerConfig::IMAGE,
        ];
    }
}
