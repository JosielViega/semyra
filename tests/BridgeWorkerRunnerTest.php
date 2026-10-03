<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Semyra\BridgeWorker\Clock;
use Semyra\BridgeWorker\ControlException;
use Semyra\BridgeWorker\ControlPlaneClient;
use Semyra\BridgeWorker\MediaProcess;
use Semyra\BridgeWorker\MediaProcessFactory;
use Semyra\BridgeWorker\SourceCatalog;
use Semyra\BridgeWorker\Watchdog;
use Semyra\BridgeWorker\WorkerConfig;
use Semyra\BridgeWorker\WorkerRunner;

require_once __DIR__ . '/../bridge-worker/bootstrap.php';

final class BridgeWorkerRunnerTest extends TestCase
{
    private string $catalogPath;
    private string $runtimePath;

    protected function setUp(): void
    {
        $this->catalogPath = tempnam(sys_get_temp_dir(), 'semyra-runner-catalog-');
        file_put_contents($this->catalogPath, '{"version":1,"sources":{"private:one":{"type":"http_mpegts","url":"https://media.example.invalid/live.ts"}}}');
        $this->runtimePath = sys_get_temp_dir() . '/semyra-worker-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        @unlink($this->catalogPath);
        if (is_dir($this->runtimePath)) {
            @rmdir($this->runtimePath);
        }
    }

    public function testRunsOneJobAndStopsWhenControlRequestsIt(): void
    {
        [$runner, $control, $media] = $this->runner([['action' => 'keep'], ['action' => 'stop']], ['starting', 'running']);

        self::assertTrue($runner->runOnce());
        self::assertTrue($media->stopped);
        self::assertSame([['stopped', null]], $control->reports);
        self::assertSame(['starting', 'running'], $control->heartbeatStatuses);
    }

    public function testLeaseLossStopsImmediatelyWithoutReportingAgainstNewOwner(): void
    {
        [$runner, $control, $media] = $this->runner([['action' => 'keep'], new ControlException('lease_lost')], ['running']);

        self::assertTrue($runner->runOnce());
        self::assertTrue($media->stopped);
        self::assertSame([], $control->reports);
    }

    public function testHeartbeatOutageFailsClosedAtLeaseDeadline(): void
    {
        [$runner, $control, $media] = $this->runner(array_merge([['action' => 'keep']], array_fill(0, 20, new ControlException('unavailable'))), ['running'], 4, 1);

        self::assertTrue($runner->runOnce());
        self::assertTrue($media->stopped);
        self::assertSame([['failed', 'lease_expired']], $control->reports);
    }

    public function testMediaFailureIsSanitizedAndReported(): void
    {
        [$runner, $control, $media] = $this->runner([['action' => 'keep']], ['failed']);
        $media->failure = 'source_invalid';

        self::assertTrue($runner->runOnce());
        self::assertSame([['failed', 'source_invalid']], $control->reports);
    }

    public function testDryRunPreservesProtocolWithoutCreatingMedia(): void
    {
        $clock = new BridgeFakeClock();
        $control = new BridgeFakeControl([['action' => 'keep'], ['action' => 'keep']]);
        $runner = new WorkerRunner($this->config(15, 5), $control, null, null, $clock);

        self::assertTrue($runner->dryRun());
        self::assertSame(['starting', 'running'], $control->heartbeatStatuses);
        self::assertSame([['stopped', null]], $control->reports);
    }

    public function testLoopSleepsAfterNoJobAndThenProcessesSequentially(): void
    {
        $clock = new BridgeFakeClock();
        $control = new BridgeLoopFakeControl();
        $media = new BridgeFakeMedia(['running']);
        $runner = new WorkerRunner(
            $this->config(15, 1), $control, new SourceCatalog($this->catalogPath),
            new BridgeFakeMediaFactory($media), $clock,
        );

        $runner->runLoop(2);

        self::assertSame(2, $control->claims);
        self::assertGreaterThanOrEqual(1.0, $clock->time - 1000.0);
        self::assertFalse($media->started);
        self::assertSame([['stopped', null]], $control->reports);
    }

    private function runner(array $heartbeats, array $states, int $lease = 15, int $heartbeat = 1): array
    {
        $clock = new BridgeFakeClock();
        $control = new BridgeFakeControl($heartbeats, $lease);
        $media = new BridgeFakeMedia($states);
        $runner = new WorkerRunner(
            $this->config($lease, $heartbeat),
            $control,
            new SourceCatalog($this->catalogPath),
            new BridgeFakeMediaFactory($media),
            $clock,
        );
        return [$runner, $control, $media];
    }

    private function config(int $lease, int $heartbeat): WorkerConfig
    {
        return new WorkerConfig(
            'testing', 'http://127.0.0.1:8010', 'wrk_' . str_repeat('a', 32), str_repeat('b', 64),
            $this->catalogPath, $heartbeat, 1, 'docker', WorkerConfig::IMAGE, $this->runtimePath,
        );
    }
}

final class BridgeFakeClock implements Clock
{
    public float $time = 1000.0;
    public function now(): float { return $this->time; }
    public function sleep(float $seconds): void { $this->time += $seconds; }
}

final class BridgeFakeControl implements ControlPlaneClient
{
    public array $heartbeatStatuses = [];
    public array $reports = [];
    public function __construct(private array $heartbeats, private readonly int $lease = 15) {}
    public function claim(string $workerId): ?array
    {
        return [
            'job_id' => 7, 'transmission_instance_id' => str_repeat('c', 32), 'source_ref' => 'private:one',
            'lease_token' => str_repeat('d', 64), 'lease_seconds' => $this->lease,
            'whip_endpoint' => 'https://whip.example.invalid/publish',
        ];
    }
    public function heartbeat(array $job, string $status): array
    {
        $this->heartbeatStatuses[] = $status;
        $response = array_shift($this->heartbeats) ?? new ControlException('unavailable');
        if ($response instanceof Throwable) { throw $response; }
        return $response;
    }
    public function report(array $job, string $status, ?string $errorCode = null): void
    {
        $this->reports[] = [$status, $errorCode];
    }
}

final class BridgeFakeMediaFactory implements MediaProcessFactory
{
    public function __construct(private readonly BridgeFakeMedia $media) {}
    public function create(Watchdog $watchdog): MediaProcess { return $this->media; }
}

final class BridgeFakeMedia implements MediaProcess
{
    public bool $stopped = false;
    public bool $started = false;
    public string $failure = 'pipeline_failed';
    public function __construct(private array $states) {}
    public function start(string $sourceUrl, string $whipEndpoint, array $job): void { $this->started = true; }
    public function poll(): string { return array_shift($this->states) ?? 'running'; }
    public function errorCode(): string { return $this->failure; }
    public function stop(): void { $this->stopped = true; }
}

final class BridgeLoopFakeControl implements ControlPlaneClient
{
    public int $claims = 0;
    public array $reports = [];
    public function claim(string $workerId): ?array
    {
        $this->claims++;
        if ($this->claims === 1) { return null; }
        return [
            'job_id' => 8, 'transmission_instance_id' => str_repeat('e', 32), 'source_ref' => 'private:one',
            'lease_token' => str_repeat('f', 64), 'lease_seconds' => 15,
            'whip_endpoint' => 'https://whip.example.invalid/publish',
        ];
    }
    public function heartbeat(array $job, string $status): array { return ['action' => 'stop']; }
    public function report(array $job, string $status, ?string $errorCode = null): void { $this->reports[] = [$status, $errorCode]; }
}
