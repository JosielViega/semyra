<?php

declare(strict_types=1);

namespace App\Services;

use Throwable;

final class MediaBridgeCoordinator
{
    private const MAX_UNSIGNED_INT = 4294967295;
    private const WORKER_PATTERN = '/^wrk_[a-f0-9]{32}$/';
    private const INSTANCE_PATTERN = '/^[a-f0-9]{32}$/';
    private const INGRESS_PATTERN = '/^[A-Za-z0-9._:-]{1,128}$/';

    public function __construct(
        private readonly MediaBridgeJobStore $jobs,
        private readonly LiveKitIngressGateway $ingress,
        private readonly LiveKitRoomContext $roomContext,
        private readonly int $leaseSeconds,
        private readonly int $maxFailures,
    ) {
    }

    /** @return null|array{job_id: int, transmission_instance_id: string, source_ref: string, lease_token: string, lease_seconds: int, whip_endpoint: string} */
    public function claim(string $workerId): ?array
    {
        $this->assertWorkerId($workerId);
        $this->reconcileStaleJobs();

        $rawToken = bin2hex(random_bytes(32));
        $leaseHash = hash('sha256', $rawToken);
        $job = $this->jobs->claim($workerId, $leaseHash, $this->leaseSeconds, $this->maxFailures);
        if ($job === null) {
            return null;
        }
        $jobId = (int) $job['id'];
        $instanceId = (string) $job['transmission_instance_id'];
        $attempt = (int) ($job['attempt_count'] ?? 0);
        if ($attempt < 1 || $attempt > self::MAX_UNSIGNED_INT) {
            throw new MediaBridgeProtocolException('bridge_unavailable', 503);
        }
        if (!$this->jobs->currentTransmissionIsEligible($job)) {
            $this->jobs->requestStop($instanceId);
            throw new MediaBridgeProtocolException('transmission_changed', 409);
        }

        $preCreateTarget = $attempt - 1;
        if (!$this->cleanupOwnedIngressesThrough($job, $preCreateTarget)) {
            $this->jobs->recordCleanupPending($jobId, $attempt);
            throw new MediaBridgeProtocolException('ingress_cleanup_pending', 503);
        }
        if (!$this->advanceLeasedCleanupThrough($job, $workerId, $leaseHash, $preCreateTarget)) {
            throw new MediaBridgeProtocolException('lease_lost', 409);
        }
        $oldIngressId = trim((string) ($job['ingress_id'] ?? ''));
        if ($oldIngressId !== '') {
            if (!$this->jobs->clearIngress($jobId, $instanceId, $workerId, $leaseHash, $oldIngressId)) {
                throw new MediaBridgeProtocolException('lease_lost', 409);
            }
        }

        try {
            $created = $this->ingress->createWhipIngress(
                MediaBridgeIngressIdentity::name($instanceId, $attempt),
                $this->roomContext->roomName((int) $job['room_id']),
                $this->roomContext->publisherIdentity((int) $job['room_id'], $instanceId),
            );
        } catch (Throwable) {
            $this->jobs->markFailed(
                $jobId, $instanceId, $workerId, $leaseHash, 'ingress_create_failed', false, true,
            );
            throw new MediaBridgeProtocolException('bridge_unavailable', 503);
        }

        $ingressId = trim((string) ($created['ingress_id'] ?? ''));
        $endpoint = trim((string) ($created['whip_endpoint'] ?? ''));
        $endpointScheme = strtolower((string) parse_url($endpoint, PHP_URL_SCHEME));
        if (preg_match(self::INGRESS_PATTERN, $ingressId) !== 1 || $endpointScheme !== 'https') {
            $this->bestEffortDelete($ingressId);
            throw new MediaBridgeProtocolException('bridge_unavailable', 503);
        }
        $lease = $this->ownedLease($jobId, $instanceId, $workerId, $rawToken);
        if ($lease === null || ($lease['desired_state'] ?? null) !== 'running'
            || !$this->jobs->currentTransmissionIsEligible($lease)
            || !$this->jobs->setIngress($jobId, $instanceId, $workerId, $leaseHash, $ingressId)) {
            $this->bestEffortDelete($ingressId);
            throw new MediaBridgeProtocolException('lease_lost', 409);
        }

        return [
            'job_id' => $jobId,
            'transmission_instance_id' => $instanceId,
            'source_ref' => (string) $job['source_ref'],
            'lease_token' => $rawToken,
            'lease_seconds' => $this->leaseSeconds,
            'whip_endpoint' => $endpoint,
        ];
    }

    /** @return array{action: string, lease_seconds?: int} */
    public function heartbeat(int $jobId, string $instanceId, string $workerId, string $rawToken, string $status): array
    {
        if (!in_array($status, MediaBridgeJobState::WORKER_HEARTBEAT, true)) {
            throw new MediaBridgeProtocolException('invalid_worker_request', 422);
        }
        $job = $this->requireLease($jobId, $instanceId, $workerId, $rawToken);
        $leaseHash = hash('sha256', $rawToken);
        if (($job['desired_state'] ?? null) !== 'running' || !$this->jobs->currentTransmissionIsEligible($job)) {
            $this->jobs->markStopping($jobId, $instanceId, $workerId, $leaseHash);
            $this->cleanupLeasedIngress($job, $workerId, $leaseHash);
            return ['action' => 'stop'];
        }
        if (!$this->jobs->renewLease(
            $jobId, $instanceId, $workerId, $leaseHash, $status, $this->leaseSeconds,
        )) {
            throw new MediaBridgeProtocolException('lease_lost', 409);
        }

        return ['action' => 'keep', 'lease_seconds' => $this->leaseSeconds];
    }

    /** @return array{action: string} */
    public function report(
        int $jobId,
        string $instanceId,
        string $workerId,
        string $rawToken,
        string $status,
        ?string $errorCode,
    ): array {
        if (!in_array($status, MediaBridgeJobState::WORKER_REPORT, true)
            || ($errorCode !== null && preg_match('/^[a-z0-9_]{1,64}$/', $errorCode) !== 1)) {
            throw new MediaBridgeProtocolException('invalid_worker_request', 422);
        }
        if ($status === 'failed' && $errorCode === null) {
            throw new MediaBridgeProtocolException('invalid_worker_request', 422);
        }
        $job = $this->requireLease($jobId, $instanceId, $workerId, $rawToken);
        $leaseHash = hash('sha256', $rawToken);
        if ($status === 'running') {
            if (($job['desired_state'] ?? null) !== 'running' || !$this->jobs->currentTransmissionIsEligible($job)) {
                $this->jobs->markStopping($jobId, $instanceId, $workerId, $leaseHash);
                $this->cleanupLeasedIngress($job, $workerId, $leaseHash);
                return ['action' => 'stop'];
            }
            if (!$this->jobs->markRunning($jobId, $instanceId, $workerId, $leaseHash)) {
                throw new MediaBridgeProtocolException('lease_lost', 409);
            }
            return ['action' => 'keep'];
        }

        $cleanupSucceeded = $this->cleanupLeasedIngress($job, $workerId, $leaseHash);
        $consumeFailureBudget = $status === 'failed' && $errorCode !== 'worker_shutdown';
        $saved = $status === 'stopped'
            ? $this->jobs->markStopped($jobId, $instanceId, $workerId, $leaseHash, !$cleanupSucceeded)
            : $this->jobs->markFailed(
                $jobId,
                $instanceId,
                $workerId,
                $leaseHash,
                (string) $errorCode,
                !$cleanupSucceeded,
                $consumeFailureBudget,
            );
        if (!$saved) {
            throw new MediaBridgeProtocolException('lease_lost', 409);
        }

        return ['action' => $status === 'stopped' ? 'stopped' : 'failed'];
    }

    private function reconcileStaleJobs(): void
    {
        $this->jobs->reconcileStaleJobs();
        foreach ($this->jobs->staleJobsForCleanup() as $job) {
            $jobId = (int) ($job['id'] ?? 0);
            if ($jobId < 1) {
                continue;
            }
            $attempt = (int) ($job['attempt_count'] ?? -1);
            if ($this->cleanupOwnedIngressesThrough($job, $attempt)) {
                $this->jobs->completeReconciledCleanup($jobId, $attempt);
            } else {
                $this->jobs->recordCleanupPending($jobId, $attempt);
            }
        }
    }

    private function requireLease(int $jobId, string $instanceId, string $workerId, string $rawToken): array
    {
        $this->assertWorkerId($workerId);
        if ($jobId < 1 || preg_match(self::INSTANCE_PATTERN, $instanceId) !== 1
            || preg_match('/^[a-f0-9]{64}$/', $rawToken) !== 1) {
            throw new MediaBridgeProtocolException('lease_lost', 409);
        }
        $job = $this->ownedLease($jobId, $instanceId, $workerId, $rawToken);
        if ($job === null) {
            throw new MediaBridgeProtocolException('lease_lost', 409);
        }

        return $job;
    }

    private function ownedLease(int $jobId, string $instanceId, string $workerId, string $rawToken): ?array
    {
        $job = $this->jobs->findLeaseContext($jobId, $instanceId, $workerId);
        $storedHash = is_array($job) ? (string) ($job['lease_token_hash'] ?? '') : '';
        if ($job === null || ($job['lease_active'] ?? false) != true
            || preg_match('/^[a-f0-9]{64}$/', $storedHash) !== 1
            || !hash_equals($storedHash, hash('sha256', $rawToken))) {
            return null;
        }

        return $job;
    }

    private function cleanupLeasedIngress(array $job, string $workerId, string $leaseHash): bool
    {
        $attempt = (int) ($job['attempt_count'] ?? -1);
        if (!$this->cleanupOwnedIngressesThrough($job, $attempt)) {
            $this->jobs->recordCleanupPending((int) $job['id'], $attempt);
            return false;
        }
        if (!$this->advanceLeasedCleanupThrough($job, $workerId, $leaseHash, $attempt)) {
            return false;
        }
        $ingressId = trim((string) ($job['ingress_id'] ?? ''));
        if ($ingressId === '') {
            return true;
        }

        return $this->jobs->clearIngress(
            (int) $job['id'],
            (string) $job['transmission_instance_id'],
            $workerId,
            $leaseHash,
            $ingressId,
        );
    }

    private function advanceLeasedCleanupThrough(
        array $job,
        string $workerId,
        string $leaseHash,
        int $throughAttempt,
    ): bool
    {
        $current = (int) ($job['cleanup_through_attempt'] ?? 0);
        if ($throughAttempt === $current) {
            return true;
        }

        return $this->jobs->advanceCleanupThrough(
            (int) $job['id'],
            (string) $job['transmission_instance_id'],
            $workerId,
            $leaseHash,
            $throughAttempt,
        );
    }

    private function cleanupOwnedIngressesThrough(array $job, int $throughAttempt): bool
    {
        $roomId = (int) ($job['room_id'] ?? 0);
        $instanceId = (string) ($job['transmission_instance_id'] ?? '');
        $attemptCount = (int) ($job['attempt_count'] ?? 0);
        $cleanupThrough = (int) ($job['cleanup_through_attempt'] ?? 0);
        if ($roomId < 1 || preg_match(self::INSTANCE_PATTERN, $instanceId) !== 1
            || $attemptCount < 0 || $attemptCount > self::MAX_UNSIGNED_INT
            || $cleanupThrough < 0 || $cleanupThrough > $attemptCount
            || $throughAttempt < $cleanupThrough || $throughAttempt > $attemptCount) {
            return false;
        }
        if ($throughAttempt === $cleanupThrough) {
            return true;
        }

        try {
            $roomName = $this->roomContext->roomName($roomId);
            $publisherIdentity = $this->roomContext->publisherIdentity($roomId, $instanceId);
            for ($attempt = $cleanupThrough + 1; $attempt <= $throughAttempt; ++$attempt) {
                $ids = $this->ingress->findOwnedIngressIds(
                    $roomName,
                    MediaBridgeIngressIdentity::name($instanceId, $attempt),
                    $publisherIdentity,
                );
                foreach ($ids as $ingressId) {
                    if (!is_string($ingressId) || preg_match(self::INGRESS_PATTERN, $ingressId) !== 1
                        || !$this->deleteIngress($ingressId)) {
                        return false;
                    }
                }
            }
        } catch (Throwable) {
            return false;
        }

        return true;
    }

    private function deleteIngress(string $ingressId): bool
    {
        try {
            $this->ingress->deleteIngress($ingressId);
            return true;
        } catch (Throwable) {
            return false;
        }
    }

    private function bestEffortDelete(string $ingressId): void
    {
        if ($ingressId !== '') {
            $this->deleteIngress($ingressId);
        }
    }

    private function assertWorkerId(string $workerId): void
    {
        if (preg_match(self::WORKER_PATTERN, $workerId) !== 1) {
            throw new MediaBridgeProtocolException('invalid_worker_request', 422);
        }
    }
}
