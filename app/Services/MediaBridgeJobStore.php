<?php

declare(strict_types=1);

namespace App\Services;

interface MediaBridgeJobStore
{
    public function create(int $roomId, string $instanceId, string $sourceRef): int;
    public function findByInstance(string $instanceId): ?array;
    public function requestStop(string $instanceId): bool;
    public function reconcileStaleJobs(): void;
    public function staleJobsForCleanup(): array;
    public function claim(string $workerId, string $leaseHash, int $leaseSeconds, int $maxFailures): ?array;
    public function currentTransmissionIsEligible(array $job): bool;
    public function findLeaseContext(int $jobId, string $instanceId, string $workerId): ?array;
    public function renewLease(int $jobId, string $instanceId, string $workerId, string $leaseHash, string $status, int $leaseSeconds): bool;
    public function markStopping(int $jobId, string $instanceId, string $workerId, string $leaseHash): bool;
    public function markRunning(int $jobId, string $instanceId, string $workerId, string $leaseHash): bool;
    public function markFailed(int $jobId, string $instanceId, string $workerId, string $leaseHash, string $errorCode, bool $keepIngress, bool $consumeFailureBudget): bool;
    public function markStopped(int $jobId, string $instanceId, string $workerId, string $leaseHash, bool $keepIngress): bool;
    public function setIngress(int $jobId, string $instanceId, string $workerId, string $leaseHash, string $ingressId): bool;
    public function clearIngress(int $jobId, string $instanceId, string $workerId, string $leaseHash, string $ingressId): bool;
    public function advanceCleanupThrough(int $jobId, string $instanceId, string $workerId, string $leaseHash, int $throughAttempt): bool;
    public function completeReconciledCleanup(int $jobId, int $expectedAttempt): bool;
    public function recordCleanupPending(int $jobId, int $expectedAttempt): bool;
}
