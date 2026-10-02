<?php

declare(strict_types=1);

namespace Tests;

use App\Services\LiveKitIngressGateway;
use App\Services\LiveKitRoomContext;
use App\Services\MediaBridgeCoordinator;
use App\Services\MediaBridgeJobStore;
use App\Services\MediaBridgeProtocolException;
use PHPUnit\Framework\TestCase;

final class MediaBridgeCoordinatorTest extends TestCase
{
    private const INSTANCE = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const WORKER = 'wrk_bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    public function testClaimCreatesInstanceBoundIngressAndStoresOnlyLeaseHashAndIngressId(): void
    {
        [$coordinator, $store, $gateway] = $this->system();
        $claim = $coordinator->claim(self::WORKER);

        self::assertSame(7, $claim['job_id']);
        self::assertSame(self::INSTANCE, $claim['transmission_instance_id']);
        self::assertSame('test_source_01', $claim['source_ref']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $claim['lease_token']);
        self::assertNotSame($claim['lease_token'], $store->job['lease_token_hash']);
        self::assertSame(hash('sha256', $claim['lease_token']), $store->job['lease_token_hash']);
        self::assertSame('INGRESS_NEW', $store->job['ingress_id']);
        self::assertArrayNotHasKey('whip_endpoint', $store->job);
        self::assertSame('smy_b_' . self::INSTANCE . '_a1', $gateway->created[0]['name']);
        self::assertMatchesRegularExpression('/^smy_r_[a-f0-9]{32}$/', $gateway->created[0]['room']);
        self::assertMatchesRegularExpression('/^smy_i_[a-f0-9]{32}$/', $gateway->created[0]['publisher']);
    }

    public function testNoJobReturnsNullAndIneligibleCurrentInstanceIsStopped(): void
    {
        [$coordinator, $store] = $this->system();
        $store->claimable = false;
        self::assertNull($coordinator->claim(self::WORKER));

        [$coordinator, $store] = $this->system();
        $store->eligible = false;
        try {
            $coordinator->claim(self::WORKER);
            self::fail('Expected stale transmission conflict.');
        } catch (MediaBridgeProtocolException $exception) {
            self::assertSame(409, $exception->status);
            self::assertSame('transmission_changed', $exception->error);
        }
        self::assertSame('stopped', $store->job['desired_state']);
    }

    public function testReclaimDeletesOldIngressBeforeCreatingNewOne(): void
    {
        [$coordinator, $store, $gateway] = $this->system();
        $store->job['ingress_id'] = 'INGRESS_OLD';
        $gateway->addOwnedIngress('INGRESS_OLD', 11, self::INSTANCE, 1);
        $coordinator->claim(self::WORKER);

        self::assertSame(['delete:INGRESS_OLD', 'create:INGRESS_NEW'], $gateway->events);
        self::assertSame('INGRESS_NEW', $store->job['ingress_id']);
    }

    public function testCreateRaceDeletesNewIngressAndNeverReturnsCredential(): void
    {
        [$coordinator, $store, $gateway] = $this->system();
        $gateway->afterCreate = static function () use ($store, $gateway): void {
            $store->leaseActive = false;
            $gateway->addOwnedIngress('INGRESS_FUTURE', 11, self::INSTANCE, 2);
        };

        $this->expectExceptionObject(new MediaBridgeProtocolException('lease_lost', 409));
        try {
            $coordinator->claim(self::WORKER);
        } finally {
            self::assertContains('INGRESS_NEW', $gateway->deleted);
            self::assertArrayHasKey('INGRESS_FUTURE', $gateway->ingresses);
        }
    }

    public function testHeartbeatRenewsValidLeaseAndRejectsWrongExpiredOrReclaimedLease(): void
    {
        [$coordinator, $store] = $this->system();
        $claim = $coordinator->claim(self::WORKER);
        self::assertSame(['action' => 'keep', 'lease_seconds' => 20], $coordinator->heartbeat(
            7, self::INSTANCE, self::WORKER, $claim['lease_token'], 'running',
        ));
        self::assertSame('running', $store->job['status']);

        foreach ([str_repeat('c', 64), str_repeat('d', 64)] as $staleToken) {
            try {
                $coordinator->heartbeat(7, self::INSTANCE, self::WORKER, $staleToken, 'running');
                self::fail('Expected lease loss.');
            } catch (MediaBridgeProtocolException $exception) {
                self::assertSame('lease_lost', $exception->error);
            }
        }
        try {
            $coordinator->heartbeat(
                7, self::INSTANCE, 'wrk_' . str_repeat('e', 32), $claim['lease_token'], 'running',
            );
            self::fail('Expected worker fencing.');
        } catch (MediaBridgeProtocolException $exception) {
            self::assertSame('lease_lost', $exception->error);
        }
        $store->leaseActive = false;
        $this->expectExceptionObject(new MediaBridgeProtocolException('lease_lost', 409));
        $coordinator->heartbeat(7, self::INSTANCE, self::WORKER, $claim['lease_token'], 'running');
    }

    public function testLeaseAIsFencedAfterReclaimIssuesLeaseB(): void
    {
        [$coordinator] = $this->system();
        $leaseA = $coordinator->claim(self::WORKER);
        $leaseB = $coordinator->claim(self::WORKER);

        self::assertNotSame($leaseA['lease_token'], $leaseB['lease_token']);
        try {
            $coordinator->heartbeat(7, self::INSTANCE, self::WORKER, $leaseA['lease_token'], 'running');
            self::fail('Lease A must be fenced after reclaim.');
        } catch (MediaBridgeProtocolException $exception) {
            self::assertSame(409, $exception->status);
            self::assertSame('lease_lost', $exception->error);
        }
        self::assertSame(['action' => 'keep', 'lease_seconds' => 20], $coordinator->heartbeat(
            7, self::INSTANCE, self::WORKER, $leaseB['lease_token'], 'running',
        ));
    }

    public function testEachClaimUsesItsAttemptGenerationAndReclaimCleansOnlyThroughCurrentAttempt(): void
    {
        [$coordinator, , $gateway] = $this->system();
        $coordinator->claim(self::WORKER);
        $gateway->findNames = [];

        $coordinator->claim(self::WORKER);

        self::assertSame([
            'smy_b_' . self::INSTANCE . '_a1',
            'smy_b_' . self::INSTANCE . '_a2',
        ], $gateway->findNames);
        self::assertSame([
            'smy_b_' . self::INSTANCE . '_a1',
            'smy_b_' . self::INSTANCE . '_a2',
        ], array_column($gateway->created, 'name'));
    }

    public function testReplacementOrEndReturnsStopWithoutRenewingLease(): void
    {
        [$coordinator, $store, $gateway] = $this->system();
        $claim = $coordinator->claim(self::WORKER);
        $store->eligible = false;

        self::assertSame(['action' => 'stop'], $coordinator->heartbeat(
            7, self::INSTANCE, self::WORKER, $claim['lease_token'], 'running',
        ));
        self::assertSame('stopped', $store->job['desired_state']);
        self::assertNull($store->job['ingress_id']);
        self::assertContains('INGRESS_NEW', $gateway->deleted);
    }

    public function testFailedAndStoppedReportsCleanupIngressAndClearLease(): void
    {
        [$coordinator, $store] = $this->system();
        $claim = $coordinator->claim(self::WORKER);
        self::assertSame(['action' => 'failed'], $coordinator->report(
            7, self::INSTANCE, self::WORKER, $claim['lease_token'], 'failed', 'decoder_failed',
        ));
        self::assertSame('failed', $store->job['status']);
        self::assertSame('decoder_failed', $store->job['last_error_code']);
        self::assertNull($store->job['lease_token_hash']);

        [$coordinator, $store] = $this->system();
        $claim = $coordinator->claim(self::WORKER);
        self::assertSame(['action' => 'stopped'], $coordinator->report(
            7, self::INSTANCE, self::WORKER, $claim['lease_token'], 'stopped', null,
        ));
        self::assertSame('stopped', $store->job['status']);
        self::assertSame('stopped', $store->job['desired_state']);
    }

    public function testRunningReportAcceptsOnlyTheCurrentLeaseAndInstance(): void
    {
        [$coordinator, $store] = $this->system();
        $claim = $coordinator->claim(self::WORKER);
        self::assertSame(['action' => 'keep'], $coordinator->report(
            7, self::INSTANCE, self::WORKER, $claim['lease_token'], 'running', null,
        ));
        self::assertSame('running', $store->job['status']);
    }

    public function testInvalidReportAndErrorCodeAreRejected(): void
    {
        [$coordinator] = $this->system();
        $claim = $coordinator->claim(self::WORKER);
        foreach ([['starting', null], ['failed', 'Unsafe Message!'], ['failed', null]] as [$status, $error]) {
            try {
                $coordinator->report(7, self::INSTANCE, self::WORKER, $claim['lease_token'], $status, $error);
                self::fail('Expected invalid worker request.');
            } catch (MediaBridgeProtocolException $exception) {
                self::assertSame('invalid_worker_request', $exception->error);
            }
        }
    }

    public function testDeleteFailureKeepsIngressAndRecordsCleanupPending(): void
    {
        [$coordinator, $store, $gateway] = $this->system();
        $claim = $coordinator->claim(self::WORKER);
        $gateway->failDelete = true;
        $coordinator->report(7, self::INSTANCE, self::WORKER, $claim['lease_token'], 'failed', 'source_failed');

        self::assertSame('INGRESS_NEW', $store->job['ingress_id']);
        self::assertSame('ingress_cleanup_pending', $store->job['last_error_code']);
    }

    public function testClaimOpportunisticallyCleansStaleTransmissionIngress(): void
    {
        [$coordinator, $store, $gateway] = $this->system();
        $store->staleRows = [[
            'id' => 99,
            'room_id' => 11,
            'transmission_instance_id' => self::INSTANCE,
            'attempt_count' => 1,
            'ingress_id' => 'INGRESS_STALE',
        ]];
        $gateway->addOwnedIngress('INGRESS_STALE', 11, self::INSTANCE, 1);
        $store->claimable = false;

        self::assertNull($coordinator->claim(self::WORKER));
        self::assertContains('INGRESS_STALE', $gateway->deleted);
        self::assertSame([99], $store->reconciledCleared);
    }

    public function testNullStoredIdOrphanIsDeletedBeforeNewIngress(): void
    {
        [$coordinator, $store, $gateway] = $this->system();
        $gateway->addOwnedIngress('INGRESS_ORPHAN', 11, self::INSTANCE, 1);

        $coordinator->claim(self::WORKER);

        self::assertSame(['delete:INGRESS_ORPHAN', 'create:INGRESS_NEW'], $gateway->events);
        self::assertSame(['INGRESS_NEW'], array_keys($gateway->ingresses));
        self::assertSame('INGRESS_NEW', $store->job['ingress_id']);
    }

    public function testCrashBeforePersistenceIsRecoveredOnNextClaim(): void
    {
        [$coordinator, $store, $gateway] = $this->system();
        $gateway->createIds = ['INGRESS_X', 'INGRESS_Y'];
        $store->throwOnSetIngress = true;
        try {
            $coordinator->claim(self::WORKER);
            self::fail('Expected simulated process crash.');
        } catch (\RuntimeException $exception) {
            self::assertSame('simulated_crash_before_ingress_persistence', $exception->getMessage());
        }
        self::assertNull($store->job['ingress_id']);
        self::assertSame(['INGRESS_X'], array_keys($gateway->ingresses));

        $store->throwOnSetIngress = false;
        $coordinator->claim(self::WORKER);

        self::assertContains('INGRESS_X', $gateway->deleted);
        self::assertSame(['INGRESS_Y'], array_keys($gateway->ingresses));
        self::assertSame('INGRESS_Y', $store->job['ingress_id']);
        self::assertSame([
            'smy_b_' . self::INSTANCE . '_a1',
            'smy_b_' . self::INSTANCE . '_a2',
        ], array_column($gateway->created, 'name'));
    }

    public function testStrictOwnershipLeavesAnotherInstanceInSameRoomUntouched(): void
    {
        [$coordinator, , $gateway] = $this->system();
        $other = 'cccccccccccccccccccccccccccccccc';
        $gateway->addOwnedIngress('INGRESS_AAA', 11, self::INSTANCE, 1);
        $gateway->addOwnedIngress('INGRESS_BBB', 11, $other, 1);

        $coordinator->claim(self::WORKER);

        self::assertContains('INGRESS_AAA', $gateway->deleted);
        self::assertNotContains('INGRESS_BBB', $gateway->deleted);
        self::assertArrayHasKey('INGRESS_BBB', $gateway->ingresses);
    }

    public function testAllExactMatchesAreDeletedBeforeCreate(): void
    {
        [$coordinator, , $gateway] = $this->system();
        $gateway->addOwnedIngress('INGRESS_OLD_A', 11, self::INSTANCE, 1);
        $gateway->addOwnedIngress('INGRESS_OLD_B', 11, self::INSTANCE, 1);

        $coordinator->claim(self::WORKER);

        self::assertSame(
            ['delete:INGRESS_OLD_A', 'delete:INGRESS_OLD_B', 'create:INGRESS_NEW'],
            $gateway->events,
        );
        self::assertSame(['INGRESS_NEW'], array_keys($gateway->ingresses));
    }

    public function testListOrDeleteFailurePreventsCreateAndMarksCleanupPending(): void
    {
        [$coordinator, $store, $gateway] = $this->system();
        $gateway->failList = true;
        try {
            $coordinator->claim(self::WORKER);
            self::fail('Expected listing failure.');
        } catch (MediaBridgeProtocolException $exception) {
            self::assertSame('ingress_cleanup_pending', $exception->error);
        }
        self::assertSame([], $gateway->created);
        self::assertSame('ingress_cleanup_pending', $store->job['last_error_code']);

        [$coordinator, $store, $gateway] = $this->system();
        $gateway->addOwnedIngress('INGRESS_ORPHAN', 11, self::INSTANCE, 1);
        $gateway->failDelete = true;
        try {
            $coordinator->claim(self::WORKER);
            self::fail('Expected delete failure.');
        } catch (MediaBridgeProtocolException $exception) {
            self::assertSame('ingress_cleanup_pending', $exception->error);
        }
        self::assertSame([], $gateway->created);
        self::assertArrayHasKey('INGRESS_ORPHAN', $gateway->ingresses);
        self::assertSame('ingress_cleanup_pending', $store->job['last_error_code']);
    }

    public function testStopDiscoversOrphanWithoutStoredIdAfterRoomDeletion(): void
    {
        [$coordinator, $store, $gateway] = $this->system();
        $claim = $coordinator->claim(self::WORKER);
        $store->job['ingress_id'] = null;
        $gateway->ingresses = [];
        $gateway->events = [];
        $gateway->addOwnedIngress('INGRESS_ORPHAN', 11, self::INSTANCE, 1);
        $store->eligible = false;

        self::assertSame(['action' => 'stop'], $coordinator->heartbeat(
            7, self::INSTANCE, self::WORKER, $claim['lease_token'], 'running',
        ));
        self::assertSame(11, $store->job['room_id'], 'room_id remains the operational snapshot.');
        self::assertContains('INGRESS_ORPHAN', $gateway->deleted);
        self::assertSame([], $gateway->ingresses);
    }

    public function testOutOfOrderAttemptOneCleanupCannotDeleteAttemptTwo(): void
    {
        [$coordinator, $store, $gateway] = $this->system();
        $claim = $coordinator->claim(self::WORKER);
        $gateway->findNames = [];
        $gateway->afterFind = static function () use ($gateway): void {
            $gateway->addOwnedIngress('INGRESS_ATTEMPT_2', 11, self::INSTANCE, 2);
        };
        $store->eligible = false;

        self::assertSame(['action' => 'stop'], $coordinator->heartbeat(
            7, self::INSTANCE, self::WORKER, $claim['lease_token'], 'running',
        ));

        self::assertSame(['smy_b_' . self::INSTANCE . '_a1'], $gateway->findNames);
        self::assertArrayHasKey('INGRESS_ATTEMPT_2', $gateway->ingresses);
        self::assertNotContains('INGRESS_ATTEMPT_2', $gateway->deleted);
    }

    public function testStoppedReconciliationCleansEveryHistoricalAttemptThroughSnapshot(): void
    {
        [$coordinator, $store, $gateway] = $this->system();
        $store->staleRows = [[
            'id' => 99,
            'room_id' => 11,
            'transmission_instance_id' => self::INSTANCE,
            'attempt_count' => 3,
            'ingress_id' => null,
        ]];
        $store->claimable = false;
        foreach ([1, 2, 3] as $attempt) {
            $gateway->addOwnedIngress('INGRESS_' . $attempt, 11, self::INSTANCE, $attempt);
        }

        self::assertNull($coordinator->claim(self::WORKER));

        self::assertSame(['INGRESS_1', 'INGRESS_2', 'INGRESS_3'], $gateway->deleted);
        self::assertSame([99], $store->reconciledCleared);
    }

    public function testStoppedJobBeforeFirstClaimNeedsNoExternalGenerationCleanup(): void
    {
        [$coordinator, $store, $gateway] = $this->system();
        $store->staleRows = [[
            'id' => 99,
            'room_id' => 11,
            'transmission_instance_id' => self::INSTANCE,
            'attempt_count' => 0,
            'ingress_id' => null,
        ]];
        $store->claimable = false;

        self::assertNull($coordinator->claim(self::WORKER));

        self::assertSame([], $gateway->findNames);
        self::assertSame([99], $store->reconciledCleared);
    }

    public function testFailedJobRetryUsesNextGeneration(): void
    {
        [$coordinator, $store, $gateway] = $this->system();
        $first = $coordinator->claim(self::WORKER);
        $coordinator->report(7, self::INSTANCE, self::WORKER, $first['lease_token'], 'failed', 'source_failed');

        $coordinator->claim(self::WORKER);

        self::assertSame(2, $store->job['attempt_count']);
        self::assertSame([
            'smy_b_' . self::INSTANCE . '_a1',
            'smy_b_' . self::INSTANCE . '_a2',
        ], array_column($gateway->created, 'name'));
    }

    public function testMaxAttemptsPreventsAnotherClaim(): void
    {
        [$coordinator, $store] = $this->system();
        $store->job['attempt_count'] = 3;
        self::assertNull($coordinator->claim(self::WORKER));
    }

    /** @return array{MediaBridgeCoordinator, FakeMediaBridgeStore, FakeIngressGateway} */
    private function system(): array
    {
        $store = new FakeMediaBridgeStore(self::INSTANCE);
        $gateway = new FakeIngressGateway();
        return [new MediaBridgeCoordinator($store, $gateway, new LiveKitRoomContext('testing'), 20, 3), $store, $gateway];
    }
}

final class FakeMediaBridgeStore implements MediaBridgeJobStore
{
    public array $job;
    public bool $eligible = true;
    public bool $claimable = true;
    public bool $leaseActive = true;
    public bool $throwOnSetIngress = false;
    public array $staleRows = [];
    public array $reconciledCleared = [];
    public function __construct(string $instance)
    {
        $this->job = ['id' => 7, 'room_id' => 11, 'transmission_instance_id' => $instance,
            'source_ref' => 'test_source_01', 'desired_state' => 'running', 'status' => 'pending',
            'worker_id' => null, 'lease_token_hash' => null, 'lease_active' => 1,
            'ingress_id' => null, 'attempt_count' => 0, 'last_error_code' => null];
    }
    public function create(int $roomId, string $instanceId, string $sourceRef): int { return 7; }
    public function findByInstance(string $instanceId): ?array { return $this->job; }
    public function requestStop(string $instanceId): bool { $this->job['desired_state']='stopped'; $this->job['status']='stopping'; return true; }
    public function reconcileStaleJobs(): void {}
    public function staleJobsForCleanup(): array { return $this->staleRows; }
    public function claim(string $workerId, string $leaseHash, int $leaseSeconds, int $maxAttempts): ?array
    {
        if (!$this->claimable || $this->job['attempt_count'] >= $maxAttempts) return null;
        $old = $this->job;
        $this->job['worker_id']=$workerId; $this->job['lease_token_hash']=$leaseHash;
        $this->job['status']='claimed'; ++$this->job['attempt_count']; $this->leaseActive=true;
        return array_merge($old, ['worker_id'=>$workerId,'lease_token_hash'=>$leaseHash,'attempt_count'=>$this->job['attempt_count']]);
    }
    public function currentTransmissionIsEligible(array $job): bool { return $this->eligible; }
    public function findLeaseContext(int $jobId, string $instanceId, string $workerId): ?array
    {
        if ($this->job['id'] !== $jobId || $this->job['transmission_instance_id'] !== $instanceId
            || $this->job['worker_id'] !== $workerId) return null;
        return array_merge($this->job, ['lease_active' => $this->leaseActive ? 1 : 0]);
    }
    public function renewLease(int $jobId,string $instanceId,string $workerId,string $leaseHash,string $status,int $leaseSeconds): bool
    { if (!$this->matches($workerId,$leaseHash)) return false; $this->job['status']=$status; return true; }
    public function markStopping(int $jobId,string $instanceId,string $workerId,string $leaseHash): bool
    { if (!$this->matches($workerId,$leaseHash)) return false; $this->job['desired_state']='stopped'; $this->job['status']='stopping'; return true; }
    public function markRunning(int $jobId,string $instanceId,string $workerId,string $leaseHash): bool
    { if (!$this->matches($workerId,$leaseHash)) return false; $this->job['status']='running'; return true; }
    public function markFailed(int $jobId,string $instanceId,string $workerId,string $leaseHash,string $errorCode,bool $keepIngress): bool
    { if (!$this->matches($workerId,$leaseHash)) return false; $this->job['status']='failed'; $this->job['last_error_code']=$errorCode; $this->clearLease(); if (!$keepIngress) $this->job['ingress_id']=null; return true; }
    public function markStopped(int $jobId,string $instanceId,string $workerId,string $leaseHash,bool $keepIngress): bool
    { if (!$this->matches($workerId,$leaseHash)) return false; $this->job['status']='stopped'; $this->job['desired_state']='stopped'; $this->clearLease(); if (!$keepIngress) $this->job['ingress_id']=null; return true; }
    public function setIngress(int $jobId,string $instanceId,string $workerId,string $leaseHash,string $ingressId): bool
    { if ($this->throwOnSetIngress) throw new \RuntimeException('simulated_crash_before_ingress_persistence'); if (!$this->matches($workerId,$leaseHash)) return false; $this->job['ingress_id']=$ingressId; return true; }
    public function clearIngress(int $jobId,string $instanceId,string $workerId,string $leaseHash,string $ingressId): bool
    { if (!$this->matches($workerId,$leaseHash) || $this->job['ingress_id'] !== $ingressId) return false; $this->job['ingress_id']=null; return true; }
    public function completeReconciledCleanup(int $jobId): void { $this->reconciledCleared[]=$jobId; }
    public function recordCleanupPending(int $jobId): void { $this->job['last_error_code']='ingress_cleanup_pending'; }
    private function matches(string $worker,string $hash): bool { return $this->leaseActive && $this->job['worker_id']===$worker && $this->job['lease_token_hash']===$hash; }
    private function clearLease(): void { $this->job['worker_id']=null; $this->job['lease_token_hash']=null; $this->leaseActive=false; }
}

final class FakeIngressGateway implements LiveKitIngressGateway
{
    public array $created=[];
    public array $deleted=[];
    public array $events=[];
    /** @var array<string, array{id: string, name: string, roomName: string, publisherIdentity: string, inputType: int}> */
    public array $ingresses=[];
    public array $createIds=['INGRESS_NEW'];
    public array $findNames=[];
    public bool $failList=false;
    public bool $failDelete=false;
    public $afterCreate=null;
    public $afterFind=null;
    public function createWhipIngress(string $name,string $roomName,string $publisherIdentity): array
    {
        $id = array_shift($this->createIds) ?? 'INGRESS_NEW';
        $this->created[]=['name'=>$name,'room'=>$roomName,'publisher'=>$publisherIdentity];
        $this->events[]='create:'.$id;
        $this->ingresses[$id]=['id'=>$id,'name'=>$name,'roomName'=>$roomName,
            'publisherIdentity'=>$publisherIdentity,'inputType'=>\Livekit\IngressInput::WHIP_INPUT];
        if (is_callable($this->afterCreate)) ($this->afterCreate)();
        return ['ingress_id'=>$id,'whip_endpoint'=>'https://whip.invalid/SAFE_TEST_CREDENTIAL'];
    }
    public function findOwnedIngressIds(string $roomName,string $ingressName,string $publisherIdentity): array
    {
        if ($this->failList) throw new \RuntimeException('safe test list failure');
        $this->findNames[]=$ingressName;
        $ids = array_values(array_map(
            static fn(array $ingress): string => $ingress['id'],
            array_filter($this->ingresses, static fn(array $ingress): bool =>
                $ingress['roomName']===$roomName && $ingress['name']===$ingressName
                && $ingress['publisherIdentity']===$publisherIdentity
                && $ingress['inputType']===\Livekit\IngressInput::WHIP_INPUT),
        ));
        if (is_callable($this->afterFind)) {
            $callback=$this->afterFind; $this->afterFind=null; $callback();
        }
        return $ids;
    }
    public function deleteIngress(string $ingressId): void
    {
        $this->events[]='delete:'.$ingressId;
        if ($this->failDelete) throw new \RuntimeException('safe test failure');
        $this->deleted[]=$ingressId; unset($this->ingresses[$ingressId]);
    }
    public function addOwnedIngress(string $id,int $roomId,string $instanceId,int $attempt): void
    {
        $context = new LiveKitRoomContext('testing');
        $this->ingresses[$id]=['id'=>$id,'name'=>'smy_b_'.$instanceId.'_a'.$attempt,'roomName'=>$context->roomName($roomId),
            'publisherIdentity'=>$context->publisherIdentity($roomId,$instanceId),
            'inputType'=>\Livekit\IngressInput::WHIP_INPUT];
    }
}
