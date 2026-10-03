<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Semyra\BridgeWorker\DockerMediaProcess;
use Semyra\BridgeWorker\SystemClock;
use Semyra\BridgeWorker\Watchdog;
use Semyra\BridgeWorker\WorkerConfig;
use Semyra\BridgeWorker\WorkerException;
use Semyra\BridgeWorker\WorkerLock;

require_once __DIR__ . '/../bridge-worker/bootstrap.php';

final class BridgeWorkerSafetyTest extends TestCase
{
    private string $runtime;

    protected function setUp(): void
    {
        $this->runtime = sys_get_temp_dir() . '/semyra-safety-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        if (is_dir($this->runtime)) {
            foreach (glob($this->runtime . '/*') ?: [] as $file) { @unlink($file); }
            @rmdir($this->runtime);
        }
    }

    public function testLockRefusesSecondProcessForSameWorkerWithoutSecretContent(): void
    {
        $first = new WorkerLock();
        $second = new WorkerLock();
        $workerId = 'wrk_' . str_repeat('a', 32);
        $first->acquire($this->runtime, $workerId);
        $lockPath = $this->runtime . '/worker-' . $workerId . '.lock';

        self::assertFileExists($lockPath);
        self::assertSame(0, filesize($lockPath));
        $this->expectException(WorkerException::class);
        try { $second->acquire($this->runtime, $workerId); } finally { $first->release(); }
    }

    public function testWatchdogUsesConservativeAnchorAndExclusiveFreshBoundary(): void
    {
        $clock = new BridgeSafetyClock();
        $watchdog = new Watchdog($this->runtime, 'wrk_' . str_repeat('b', 32), $clock);
        $clock->time = 1004;
        $watchdog->touchAt(1000);

        self::assertSame('1000', file_get_contents($watchdog->path()));
        $clock->time = 1014;
        self::assertTrue($watchdog->fresh(15));
        $clock->time = 1015;
        self::assertFalse($watchdog->fresh(15));
        $watchdog->touchAt(2000);
        self::assertSame('1015', file_get_contents($watchdog->path()));
        $watchdog->remove();
        self::assertFileDoesNotExist($watchdog->path());
    }

    public function testFeederAndEmergencyWatchdogUseConservativeStaleEnforcement(): void
    {
        $feeder = (string) file_get_contents(dirname(__DIR__) . '/bridge-worker/feeder.php');
        $emergency = (string) file_get_contents(dirname(__DIR__) . '/bridge-worker/media-watchdog.php');
        $normal = (string) file_get_contents(dirname(__DIR__) . '/bridge-worker/src/DockerMediaProcess.php');

        self::assertStringContainsString('$now - $timestamp < $maximumAge', $feeder);
        self::assertStringContainsString('$now - $timestamp >= $maximumAge', $emergency);
        self::assertStringNotContainsString("'stop', '--time'", $emergency);
        self::assertLessThan(strpos($emergency, "'rm', '-f'"), strpos($emergency, "'kill'"));
        self::assertStringContainsString("dockerCleanup('stop')", $normal);
        self::assertStringContainsString("dockerCleanup('kill')", $normal);
        self::assertStringContainsString("dockerCleanup('rm')", $normal);
    }

    public function testDockerCommandContainsNoRuntimeSecretsAndUsesPinnedPipeline(): void
    {
        $secret = str_repeat('9', 64);
        $whip = 'https://whip.example.invalid/secret-token';
        $provider = 'http://provider.example.invalid/private-stream';
        $config = new WorkerConfig(
            'testing', 'http://127.0.0.1:8010', 'wrk_' . str_repeat('a', 32), $secret, '',
            5, 2, 'docker', WorkerConfig::IMAGE, $this->runtime,
        );
        $process = new DockerMediaProcess($config, new Watchdog($this->runtime, $config->workerId, new SystemClock()));
        $method = new ReflectionMethod($process, 'dockerCommand');
        $command = $method->invoke($process, 5, str_repeat('c', 32));
        $joined = implode(' ', $command);

        self::assertStringNotContainsString($secret, $joined);
        self::assertStringNotContainsString($whip, $joined);
        self::assertStringNotContainsString($provider, $joined);
        self::assertStringContainsString('-e WHIP_ENDPOINT', $joined);
        self::assertStringContainsString('fdsrc fd=0', $joined);
        self::assertStringContainsString('h264parse', $joined);
        self::assertStringContainsString('rtph264pay', $joined);
        self::assertStringContainsString('avdec_aac', $joined);
        self::assertStringContainsString('opusenc bitrate=96000', $joined);
        self::assertStringNotContainsString('avdec_h264', $joined);
        self::assertStringNotContainsString('x264enc', $joined);
        self::assertStringNotContainsString('openh264enc', $joined);
    }

    public function testCliFailureOutputNeverContainsRuntimeSecrets(): void
    {
        $secret = str_repeat('9', 64);
        $provider = 'http://provider.example.invalid/user/password/stream';
        $whip = 'https://whip.example.invalid/private-token';
        $environment = getenv();
        $environment = is_array($environment) ? $environment : [];
        $environment = array_merge($environment, [
            'APP_ENV' => 'testing',
            'SEMYRA_CONTROL_URL' => 'http://127.0.0.1:8010',
            'SEMYRA_WORKER_ID' => 'wrk_' . str_repeat('a', 32),
            'SEMYRA_WORKER_SECRET' => $secret,
            'SEMYRA_SOURCE_CATALOG_PATH' => $this->runtime . '/missing.json',
            'SEMYRA_GSTREAMER_IMAGE' => WorkerConfig::IMAGE,
            'PROVIDER_URL' => $provider,
            'WHIP_ENDPOINT' => $whip,
        ]);
        $pipes = [];
        $process = proc_open(
            [PHP_BINARY, dirname(__DIR__) . '/bridge-worker/worker.php', '--once'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes, null, $environment, ['bypass_shell' => true],
        );
        self::assertIsResource($process);
        $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        foreach ($pipes as $pipe) { fclose($pipe); }
        self::assertNotSame(0, proc_close($process));
        self::assertStringNotContainsString($secret, $output);
        self::assertStringNotContainsString($provider, $output);
        self::assertStringNotContainsString($whip, $output);
    }
}

final class BridgeSafetyClock implements \Semyra\BridgeWorker\Clock
{
    public float $time = 1000;
    public function now(): float { return $this->time; }
    public function sleep(float $seconds): void { $this->time += $seconds; }
}
