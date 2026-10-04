<?php

declare(strict_types=1);

namespace Tests;

use App\Core\Database;
use App\Repositories\MediaBridgeJobRepository;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;

final class MediaBridgeJobRepositoryTest extends TestCase
{
    private RecordingBridgePdo $pdo;
    private MediaBridgeJobRepository $repository;

    protected function setUp(): void
    {
        $this->pdo = new RecordingBridgePdo();
        $database = new Database([]);
        (new \ReflectionProperty($database, 'connection'))->setValue($database, $this->pdo);
        $this->repository = new MediaBridgeJobRepository($database);
    }

    public function testCreateStoresOpaqueReferenceAndInstanceBoundPendingState(): void
    {
        self::assertSame(17, $this->repository->create(7, str_repeat('a', 32), 'test_source_01'));
        self::assertStringContainsString('media_bridge_jobs', $this->pdo->lastQuery);
        self::assertStringContainsString("'running', 'pending'", $this->pdo->lastQuery);
        self::assertSame('test_source_01', $this->pdo->lastParams['source_ref']);
    }

    public function testClaimUsesCurrentIptvLiveGuardFailureBudgetAndGenerationOverflowGuard(): void
    {
        $this->pdo->fetchRow = $this->job();
        $claimed = $this->repository->claim('wrk_' . str_repeat('b', 32), str_repeat('c', 64), 20, 3);

        self::assertSame(7, $claimed['id']);
        self::assertStringContainsString("transmission.source_type = 'iptv'", $this->pdo->queries[0]);
        self::assertStringContainsString("transmission.media_mode = 'live'", $this->pdo->queries[0]);
        self::assertStringContainsString('job.failure_count < :max_failures', $this->pdo->queries[0]);
        self::assertStringContainsString('job.attempt_count < :max_attempt_count', $this->pdo->queries[0]);
        self::assertStringContainsString("job.status IN ('pending', 'failed')", $this->pdo->queries[0]);
        self::assertStringContainsString('job.cleanup_through_attempt = job.attempt_count', $this->pdo->queries[0]);
        self::assertStringNotContainsString('lease_expires_at < CURRENT_TIMESTAMP(3)', $this->pdo->queries[0]);
        self::assertStringContainsString('FOR UPDATE', $this->pdo->queries[0]);
        self::assertStringContainsString('lease_token_hash = :lease_token_hash', $this->pdo->queries[1]);
        self::assertSame(str_repeat('c', 64), $this->pdo->lastParams['lease_token_hash']);
        self::assertSame(4294967295, $this->pdo->executions[0]['max_attempt_count']);
    }

    public function testLeaseMutationsAreFencedByWorkerInstanceHashAndExpiry(): void
    {
        self::assertTrue($this->repository->renewLease(
            7, str_repeat('a', 32), 'wrk_' . str_repeat('b', 32), str_repeat('c', 64), 'running', 20,
        ));
        self::assertStringContainsString('transmission_instance_id = :transmission_instance_id', $this->pdo->lastQuery);
        self::assertStringContainsString('worker_id = :worker_id', $this->pdo->lastQuery);
        self::assertStringContainsString('lease_token_hash = :lease_token_hash', $this->pdo->lastQuery);
        self::assertStringContainsString('lease_expires_at >= CURRENT_TIMESTAMP(3)', $this->pdo->lastQuery);
        self::assertStringContainsString("desired_state = 'running'", $this->pdo->lastQuery);
    }

    public function testFailedReportPreservesRunningDesiredStateForRetry(): void
    {
        self::assertTrue($this->repository->markFailed(
            7,
            str_repeat('a', 32),
            'wrk_' . str_repeat('b', 32),
            str_repeat('c', 64),
            'worker_shutdown',
            false,
            false,
        ));

        self::assertStringContainsString("status = 'failed'", $this->pdo->lastQuery);
        self::assertStringContainsString('last_error_code = :last_error_code', $this->pdo->lastQuery);
        self::assertStringContainsString('failure_count = failure_count + :failure_increment', $this->pdo->lastQuery);
        self::assertStringNotContainsString('desired_state =', $this->pdo->lastQuery);
        self::assertSame('worker_shutdown', $this->pdo->lastParams['last_error_code']);
        self::assertSame(0, $this->pdo->lastParams['failure_increment']);
    }

    public function testRealFailureExplicitlyConsumesOneFailure(): void
    {
        self::assertTrue($this->repository->markFailed(
            7, str_repeat('a', 32), 'wrk_' . str_repeat('b', 32), str_repeat('c', 64),
            'source_failed', false, true,
        ));

        self::assertSame(1, $this->pdo->lastParams['failure_increment']);
    }

    public function testRequestStopAndStaleReconciliationUseInstanceAsFence(): void
    {
        self::assertTrue($this->repository->requestStop(str_repeat('a', 32)));
        self::assertStringContainsString('transmission_instance_id = :transmission_instance_id', $this->pdo->lastQuery);
        $this->repository->reconcileStaleJobs();
        $queries = implode("\n", $this->pdo->queries);
        self::assertStringContainsString('transmission.instance_id = job.transmission_instance_id', $queries);
        self::assertStringContainsString("job.desired_state = 'stopped'", $queries);
        self::assertStringContainsString("job.status = 'failed'", $queries);
        self::assertStringContainsString('job.failure_count = job.failure_count + 1', $queries);
        self::assertStringContainsString("job.last_error_code = 'lease_expired'", $queries);
        self::assertStringContainsString("job.status IN ('claimed', 'starting', 'running')", $queries);
        self::assertStringContainsString("status = 'stopped'", $queries);
        self::assertStringNotContainsString('ingress_id IS NULL', $queries);
    }

    public function testCleanupDiscoveryIncludesSnapshotContextEvenWithoutStoredIngressId(): void
    {
        self::assertSame([], $this->repository->staleJobsForCleanup());
        self::assertStringContainsString(
            'failure_count, cleanup_through_attempt, ingress_id, last_error_code',
            $this->pdo->lastQuery,
        );
        self::assertStringContainsString("status = 'stopping'", $this->pdo->lastQuery);
        self::assertStringContainsString("desired_state = 'running' AND status = 'failed'", $this->pdo->lastQuery);
        self::assertStringContainsString('cleanup_through_attempt < attempt_count', $this->pdo->lastQuery);
        self::assertStringNotContainsString('ingress_id IS NOT NULL', $this->pdo->lastQuery);
    }

    public function testReconciledCleanupFinalizesOnlyStoppedDesiredState(): void
    {
        self::assertTrue($this->repository->completeReconciledCleanup(7, 3));
        self::assertStringContainsString('ingress_id = NULL', $this->pdo->lastQuery);
        self::assertStringContainsString(
            'cleanup_through_attempt = GREATEST(cleanup_through_attempt, :through_attempt)',
            $this->pdo->lastQuery,
        );
        self::assertStringContainsString('attempt_count = :expected_attempt', $this->pdo->lastQuery);
        self::assertStringContainsString("status = 'stopping'", $this->pdo->lastQuery);
        self::assertStringContainsString('lease_expires_at < CURRENT_TIMESTAMP(3)', $this->pdo->lastQuery);

        self::assertTrue($this->repository->recordCleanupPending(7, 3));
        self::assertSame(['id' => 7, 'expected_attempt' => 3, 'cleanup_target' => 3], $this->pdo->lastParams);
        self::assertStringContainsString('attempt_count = :expected_attempt', $this->pdo->lastQuery);
        self::assertStringContainsString('cleanup_through_attempt < :cleanup_target', $this->pdo->lastQuery);
        self::assertStringNotContainsString('ingress_id = :ingress_id', $this->pdo->lastQuery);
    }

    public function testLeaseScopedWatermarkAdvanceIsMonotonicAndBoundedByAttempt(): void
    {
        self::assertTrue($this->repository->advanceCleanupThrough(
            7, str_repeat('a', 32), 'wrk_' . str_repeat('b', 32), str_repeat('c', 64), 4,
        ));

        self::assertStringContainsString('cleanup_through_attempt = :through_value', $this->pdo->lastQuery);
        self::assertStringContainsString('cleanup_through_attempt < :through_floor', $this->pdo->lastQuery);
        self::assertStringContainsString(':through_limit <= attempt_count', $this->pdo->lastQuery);
        self::assertStringContainsString('lease_expires_at >= CURRENT_TIMESTAMP(3)', $this->pdo->lastQuery);
    }

    private function job(): array
    {
        return ['id'=>7,'room_id'=>11,'transmission_instance_id'=>str_repeat('a',32),'source_ref'=>'test_source_01',
            'desired_state'=>'running','status'=>'pending','worker_id'=>null,'lease_token_hash'=>null,
            'lease_expires_at'=>null,'ingress_id'=>null,'attempt_count'=>0,'failure_count'=>0,
            'cleanup_through_attempt'=>0,'last_error_code'=>null];
    }
}

final class RecordingBridgePdo extends PDO
{
    public string $lastQuery='';
    public array $lastParams=[];
    public array $queries=[];
    public array $executions=[];
    public array|false $fetchRow=false;
    private bool $transaction=false;
    public function __construct() {}
    public function prepare(string $query, array $options=[]): PDOStatement|false
    { $this->lastQuery=$query; $this->queries[]=$query; return new RecordingBridgeStatement($this,$query); }
    public function exec(string $statement): int|false { $this->lastQuery=$statement; $this->queries[]=$statement; return 1; }
    public function query(string $query, ?int $fetchMode=null, mixed ...$fetchModeArgs): PDOStatement|false
    { $this->lastQuery=$query; $this->queries[]=$query; return new RecordingBridgeStatement($this,$query); }
    public function lastInsertId(?string $name=null): string|false { return '17'; }
    public function beginTransaction(): bool { $this->transaction=true; return true; }
    public function commit(): bool { $this->transaction=false; return true; }
    public function rollBack(): bool { $this->transaction=false; return true; }
    public function inTransaction(): bool { return $this->transaction; }
}

final class RecordingBridgeStatement extends PDOStatement
{
    public function __construct(private readonly RecordingBridgePdo $pdo, private readonly string $query) {}
    public function execute(?array $params=null): bool { $this->pdo->lastParams=$params??[]; $this->pdo->executions[]=$params??[]; return true; }
    public function rowCount(): int { return 1; }
    public function fetch(int $mode=PDO::FETCH_DEFAULT,int $cursorOrientation=PDO::FETCH_ORI_NEXT,int $cursorOffset=0): mixed
    { $row=$this->pdo->fetchRow; $this->pdo->fetchRow=false; return $row; }
    public function fetchAll(int $mode=PDO::FETCH_DEFAULT,mixed ...$args): array { return []; }
    public function fetchColumn(int $column=0): mixed { return 1; }
}
