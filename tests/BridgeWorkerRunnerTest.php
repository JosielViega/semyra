<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Semyra\BridgeWorker\Clock;
use Semyra\BridgeWorker\ControlException;
use Semyra\BridgeWorker\ControlPlaneClient;
use Semyra\BridgeWorker\MediaProcess;
use Semyra\BridgeWorker\MediaProcessFactory;
use Semyra\BridgeWorker\SourceCatalog;
use Semyra\BridgeWorker\Watchdog;
use Semyra\BridgeWorker\WorkerConfig;
use Semyra\BridgeWorker\WorkerException;
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

    public function testDefaultTimingPolicyAllowsMediaToStart(): void
    {
        [$runner, $control, $media] = $this->runner([['action' => 'keep'], ['action' => 'stop']], ['running'], 20, 5);

        self::assertTrue($runner->runOnce());
        self::assertTrue($media->started);
        self::assertTrue($media->stopped);
        self::assertSame([['stopped', null]], $control->reports);
    }

    public function testInitialSlowHeartbeatAnchorsWatchdogAtRequestStart(): void
    {
        [$runner, $control, $media] = $this->runner([
            new BridgeDelayedHeartbeat(4, ['action' => 'keep']),
            ['action' => 'stop'],
        ], ['running'], 20, 5);

        self::assertTrue($runner->runOnce());
        self::assertSame(1000.0, $control->heartbeatStartedAt[0]);
        self::assertSame(1004.0, $control->heartbeatFinishedAt[0]);
        self::assertContains(1000, $media->watchdogTimestamps);
        self::assertNotContains(1004, $media->watchdogTimestamps);
    }

    public function testRecurringSlowHeartbeatAnchorsWatchdogAndScheduleAtRequestStart(): void
    {
        [$runner, $control, $media] = $this->runner([
            ['action' => 'keep'],
            new BridgeDelayedHeartbeat(4, ['action' => 'keep']),
            ['action' => 'stop'],
        ], ['running'], 10, 1);

        self::assertTrue($runner->runOnce());
        $slowHeartbeatStartedAt = $control->heartbeatStartedAt[1];
        self::assertSame($slowHeartbeatStartedAt + 4, $control->heartbeatFinishedAt[1]);
        self::assertSame((int) $slowHeartbeatStartedAt, end($media->watchdogTimestamps));
        self::assertLessThan(
            $control->heartbeatFinishedAt[1] + $this->config(10, 1)->heartbeatSeconds,
            $control->heartbeatStartedAt[2],
        );
    }

    public function testOutageAfterSlowKeepExpiresFromRequestStart(): void
    {
        [$runner, $control, $media] = $this->runner([
            ['action' => 'keep'],
            new BridgeDelayedHeartbeat(4, ['action' => 'keep']),
            new ControlException('unavailable'),
        ], ['running'], 10, 1);

        self::assertTrue($runner->runOnce());
        $conservativeDeadline = $control->heartbeatStartedAt[1] + 10 - 3;
        self::assertSame([['failed', 'lease_expired']], $control->reports);
        self::assertEqualsWithDelta($conservativeDeadline, $control->reportTimes[0], 0.11);
        self::assertLessThan($control->heartbeatFinishedAt[1] + 10 - 3, $control->reportTimes[0]);
        self::assertTrue($media->stopped);
    }

    #[DataProvider('unsafeLeaseProvider')]
    public function testUnsafeLeaseFailsBeforeStartingMedia(int $heartbeatSeconds, int $leaseSeconds): void
    {
        [$runner, $control, $media] = $this->runner([], [], $leaseSeconds, $heartbeatSeconds);

        try {
            $runner->runOnce();
            self::fail('Unsafe lease was accepted.');
        } catch (WorkerException $exception) {
            self::assertSame('lease_invalid', $exception->errorCode);
        }
        self::assertFalse($media->started);
        self::assertFalse($media->stopped);
        self::assertSame([], $control->heartbeatStatuses);
        self::assertSame([['failed', 'lease_invalid']], $control->reports);
    }

    public static function unsafeLeaseProvider(): array
    {
        return [
            'watchdog exceeds lease' => [5, 10],
            'watchdog equals lease' => [5, 15],
            'slow heartbeat exceeds lease' => [10, 20],
        ];
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
        [$runner, $control, $media] = $this->runner(array_merge([['action' => 'keep']], array_fill(0, 20, new ControlException('unavailable'))), ['running'], 6, 1);

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
        $control = new BridgeFakeControl($heartbeats, $lease, $clock);
        $media = new BridgeFakeMedia($states);
        $runner = new WorkerRunner(
            $this->config($lease, $heartbeat),
            $control,
            new SourceCatalog($this->catalogPath),
            new BridgeFakeMediaFactory($media),
            $clock,
        );
        return [$runner, $control, $media, $clock];
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
    public array $heartbeatStartedAt = [];
    public array $heartbeatFinishedAt = [];
    public array $reports = [];
    public array $reportTimes = [];
    public function __construct(
        private array $heartbeats,
        private readonly int $lease = 15,
        private readonly ?BridgeFakeClock $clock = null,
    ) {}
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
        $this->heartbeatStartedAt[] = $this->clock?->now();
        $this->heartbeatStatuses[] = $status;
        $response = array_shift($this->heartbeats) ?? new ControlException('unavailable');
        if ($response instanceof BridgeDelayedHeartbeat) {
            $this->clock?->sleep($response->delaySeconds);
            $response = $response->response;
        }
        $this->heartbeatFinishedAt[] = $this->clock?->now();
        if ($response instanceof Throwable) { throw $response; }
        return $response;
    }
    public function report(array $job, string $status, ?string $errorCode = null): void
    {
        $this->reports[] = [$status, $errorCode];
        $this->reportTimes[] = $this->clock?->now();
    }
}

final class BridgeDelayedHeartbeat
{
    public function __construct(public readonly float $delaySeconds, public readonly mixed $response) {}
}

final class BridgeFakeMediaFactory implements MediaProcessFactory
{
    public function __construct(private readonly BridgeFakeMedia $media) {}
    public function create(Watchdog $watchdog): MediaProcess
    {
        $this->media->watchdog = $watchdog;
        return $this->media;
    }
}

final class BridgeFakeMedia implements MediaProcess
{
    public bool $stopped = false;
    public bool $started = false;
    public string $failure = 'pipeline_failed';
    public ?Watchdog $watchdog = null;
    public array $watchdogTimestamps = [];
    public function __construct(private array $states) {}
    public function start(string $sourceUrl, string $whipEndpoint, array $job): void { $this->started = true; }
    public function poll(): string
    {
        if ($this->watchdog !== null && is_file($this->watchdog->path())) {
            $this->watchdogTimestamps[] = (int) file_get_contents($this->watchdog->path());
        }
        return array_shift($this->states) ?? 'running';
    }
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
