<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use App\Services\MediaBridgeJobStore;
use PDO;
use Throwable;

final class MediaBridgeJobRepository implements MediaBridgeJobStore
{
    private const INSTANCE_PATTERN = '/^[a-f0-9]{32}$/';
    private const SOURCE_PATTERN = '/^[a-zA-Z0-9._:-]{1,96}$/';

    public function __construct(private readonly Database $database)
    {
    }

    public function create(int $roomId, string $instanceId, string $sourceRef): int
    {
        if ($roomId < 1 || preg_match(self::INSTANCE_PATTERN, $instanceId) !== 1
            || preg_match(self::SOURCE_PATTERN, $sourceRef) !== 1) {
            throw new \InvalidArgumentException('Media bridge job identity is invalid.');
        }
        $statement = $this->database->connection()->prepare(
            'INSERT INTO media_bridge_jobs '
            . '(room_id, transmission_instance_id, source_ref, desired_state, status) '
            . "VALUES (:room_id, :transmission_instance_id, :source_ref, 'running', 'pending')",
        );
        $statement->execute([
            'room_id' => $roomId,
            'transmission_instance_id' => $instanceId,
            'source_ref' => $sourceRef,
        ]);

        return (int) $this->database->connection()->lastInsertId();
    }

    public function findByInstance(string $instanceId): ?array
    {
        $statement = $this->database->connection()->prepare(
            'SELECT * FROM media_bridge_jobs WHERE transmission_instance_id = :transmission_instance_id LIMIT 1',
        );
        $statement->execute(['transmission_instance_id' => $instanceId]);
        $job = $statement->fetch();

        return is_array($job) ? $job : null;
    }

    public function requestStop(string $instanceId): bool
    {
        $statement = $this->database->connection()->prepare(
            "UPDATE media_bridge_jobs SET desired_state = 'stopped', "
            . "status = CASE WHEN status = 'stopped' THEN status ELSE 'stopping' END "
            . 'WHERE transmission_instance_id = :transmission_instance_id',
        );
        $statement->execute(['transmission_instance_id' => $instanceId]);

        return $statement->rowCount() === 1;
    }

    public function reconcileStaleJobs(): void
    {
        $pdo = $this->database->connection();
        $pdo->exec(
            "UPDATE media_bridge_jobs job "
            . 'LEFT JOIN room_transmissions transmission '
            . 'ON transmission.room_id = job.room_id '
            . 'AND transmission.instance_id = job.transmission_instance_id '
            . "AND transmission.source_type = 'iptv' AND transmission.media_mode = 'live' "
            . "SET job.desired_state = 'stopped', "
            . "job.status = CASE WHEN job.status = 'stopped' THEN job.status ELSE 'stopping' END "
            . "WHERE job.desired_state = 'running' AND transmission.room_id IS NULL",
        );
    }

    public function staleJobsForCleanup(): array
    {
        $statement = $this->database->connection()->query(
            'SELECT id, room_id, transmission_instance_id, attempt_count, ingress_id FROM media_bridge_jobs '
            . "WHERE desired_state = 'stopped' AND "
            . "(status = 'stopping' OR last_error_code = 'ingress_cleanup_pending')",
        );

        return $statement === false ? [] : $statement->fetchAll();
    }

    public function claim(string $workerId, string $leaseHash, int $leaseSeconds, int $maxAttempts): ?array
    {
        $pdo = $this->database->connection();
        $pdo->beginTransaction();
        try {
            $statement = $pdo->prepare(
                'SELECT job.* FROM media_bridge_jobs job '
                . 'INNER JOIN room_transmissions transmission '
                . 'ON transmission.room_id = job.room_id '
                . 'AND transmission.instance_id = job.transmission_instance_id '
                . "AND transmission.source_type = 'iptv' AND transmission.media_mode = 'live' "
                . "WHERE job.desired_state = 'running' AND job.attempt_count < :max_attempts "
                . "AND (job.status = 'pending' OR job.status = 'failed' "
                . "OR (job.status IN ('claimed', 'starting', 'running') "
                . 'AND job.lease_expires_at < CURRENT_TIMESTAMP(3))) '
                . 'ORDER BY job.id ASC LIMIT 1 FOR UPDATE',
            );
            $statement->execute(['max_attempts' => $maxAttempts]);
            $job = $statement->fetch();
            if (!is_array($job)) {
                $pdo->commit();
                return null;
            }

            $update = $pdo->prepare(
                "UPDATE media_bridge_jobs SET worker_id = :worker_id, lease_token_hash = :lease_token_hash, "
                . 'lease_expires_at = DATE_ADD(CURRENT_TIMESTAMP(3), INTERVAL :lease_seconds SECOND), '
                . "status = 'claimed', attempt_count = attempt_count + 1, last_error_code = NULL "
                . 'WHERE id = :id',
            );
            $update->execute([
                'worker_id' => $workerId,
                'lease_token_hash' => $leaseHash,
                'lease_seconds' => $leaseSeconds,
                'id' => $job['id'],
            ]);
            $pdo->commit();
            $job['worker_id'] = $workerId;
            $job['lease_token_hash'] = $leaseHash;
            $job['attempt_count'] = (int) $job['attempt_count'] + 1;
            $job['status'] = 'claimed';

            return $job;
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }

    public function currentTransmissionIsEligible(array $job): bool
    {
        $statement = $this->database->connection()->prepare(
            'SELECT 1 FROM room_transmissions WHERE room_id = :room_id '
            . 'AND instance_id = :transmission_instance_id '
            . "AND source_type = 'iptv' AND media_mode = 'live' LIMIT 1",
        );
        $statement->execute([
            'room_id' => $job['room_id'] ?? null,
            'transmission_instance_id' => $job['transmission_instance_id'] ?? '',
        ]);

        return $statement->fetchColumn() !== false;
    }

    public function findLeaseContext(int $jobId, string $instanceId, string $workerId): ?array
    {
        $statement = $this->database->connection()->prepare(
            'SELECT job.*, (job.lease_expires_at >= CURRENT_TIMESTAMP(3)) AS lease_active '
            . 'FROM media_bridge_jobs job WHERE id = :id '
            . 'AND transmission_instance_id = :transmission_instance_id AND worker_id = :worker_id LIMIT 1',
        );
        $statement->execute(['id' => $jobId, 'transmission_instance_id' => $instanceId, 'worker_id' => $workerId]);
        $job = $statement->fetch();

        return is_array($job) ? $job : null;
    }

    public function renewLease(int $jobId, string $instanceId, string $workerId, string $leaseHash, string $status, int $leaseSeconds): bool
    {
        if (!in_array($status, ['starting', 'running'], true)) {
            return false;
        }

        return $this->leaseMutation(
            "status = :status, lease_expires_at = DATE_ADD(CURRENT_TIMESTAMP(3), INTERVAL :lease_seconds SECOND)",
            $jobId, $instanceId, $workerId, $leaseHash,
            ['status' => $status, 'lease_seconds' => $leaseSeconds],
            "AND desired_state = 'running'",
        );
    }

    public function markStopping(int $jobId, string $instanceId, string $workerId, string $leaseHash): bool
    {
        return $this->leaseMutation("desired_state = 'stopped', status = 'stopping'", $jobId, $instanceId, $workerId, $leaseHash);
    }

    public function markRunning(int $jobId, string $instanceId, string $workerId, string $leaseHash): bool
    {
        return $this->leaseMutation("status = 'running'", $jobId, $instanceId, $workerId, $leaseHash, [], "AND desired_state = 'running'");
    }

    public function markFailed(int $jobId, string $instanceId, string $workerId, string $leaseHash, string $errorCode, bool $keepIngress): bool
    {
        $set = "status = 'failed', last_error_code = :last_error_code, worker_id = NULL, "
            . 'lease_token_hash = NULL, lease_expires_at = NULL';
        if (!$keepIngress) {
            $set .= ', ingress_id = NULL';
        }

        return $this->leaseMutation($set, $jobId, $instanceId, $workerId, $leaseHash, ['last_error_code' => $errorCode]);
    }

    public function markStopped(int $jobId, string $instanceId, string $workerId, string $leaseHash, bool $keepIngress): bool
    {
        $set = "desired_state = 'stopped', status = 'stopped', stopped_at = CURRENT_TIMESTAMP(3), "
            . 'worker_id = NULL, lease_token_hash = NULL, lease_expires_at = NULL, last_error_code = '
            . ($keepIngress ? "'ingress_cleanup_pending'" : 'NULL');
        if (!$keepIngress) {
            $set .= ', ingress_id = NULL';
        }

        return $this->leaseMutation($set, $jobId, $instanceId, $workerId, $leaseHash);
    }

    public function setIngress(int $jobId, string $instanceId, string $workerId, string $leaseHash, string $ingressId): bool
    {
        return $this->leaseMutation(
            'ingress_id = :ingress_id', $jobId, $instanceId, $workerId, $leaseHash,
            ['ingress_id' => $ingressId], "AND desired_state = 'running'",
        );
    }

    public function clearIngress(int $jobId, string $instanceId, string $workerId, string $leaseHash, string $ingressId): bool
    {
        return $this->leaseMutation(
            'ingress_id = NULL', $jobId, $instanceId, $workerId, $leaseHash,
            ['expected_ingress_id' => $ingressId], 'AND ingress_id = :expected_ingress_id',
        );
    }

    public function completeReconciledCleanup(int $jobId): void
    {
        $statement = $this->database->connection()->prepare(
            "UPDATE media_bridge_jobs SET ingress_id = NULL, last_error_code = NULL, "
            . "stopped_at = CASE WHEN status = 'stopping' "
            . 'AND (lease_expires_at IS NULL OR lease_expires_at < CURRENT_TIMESTAMP(3)) '
            . 'THEN CURRENT_TIMESTAMP(3) ELSE stopped_at END, '
            . "worker_id = CASE WHEN status = 'stopping' "
            . 'AND (lease_expires_at IS NULL OR lease_expires_at < CURRENT_TIMESTAMP(3)) '
            . 'THEN NULL ELSE worker_id END, '
            . "lease_token_hash = CASE WHEN status = 'stopping' "
            . 'AND (lease_expires_at IS NULL OR lease_expires_at < CURRENT_TIMESTAMP(3)) '
            . 'THEN NULL ELSE lease_token_hash END, '
            . "lease_expires_at = CASE WHEN status = 'stopping' "
            . 'AND (lease_expires_at IS NULL OR lease_expires_at < CURRENT_TIMESTAMP(3)) '
            . 'THEN NULL ELSE lease_expires_at END, '
            . "status = CASE WHEN status = 'stopping' "
            . 'AND (lease_expires_at IS NULL OR lease_expires_at < CURRENT_TIMESTAMP(3)) '
            . "THEN 'stopped' ELSE status END "
            . "WHERE id = :id AND desired_state = 'stopped'",
        );
        $statement->execute(['id' => $jobId]);
    }

    public function recordCleanupPending(int $jobId): void
    {
        $statement = $this->database->connection()->prepare(
            "UPDATE media_bridge_jobs SET last_error_code = 'ingress_cleanup_pending' "
            . 'WHERE id = :id',
        );
        $statement->execute(['id' => $jobId]);
    }

    private function leaseMutation(
        string $set,
        int $jobId,
        string $instanceId,
        string $workerId,
        string $leaseHash,
        array $extra = [],
        string $extraWhere = '',
    ): bool {
        $statement = $this->database->connection()->prepare(
            'UPDATE media_bridge_jobs SET ' . $set . ' WHERE id = :id '
            . 'AND transmission_instance_id = :transmission_instance_id '
            . 'AND worker_id = :worker_id AND lease_token_hash = :lease_token_hash '
            . 'AND lease_expires_at >= CURRENT_TIMESTAMP(3) ' . $extraWhere,
        );
        $statement->execute(array_merge($extra, [
            'id' => $jobId,
            'transmission_instance_id' => $instanceId,
            'worker_id' => $workerId,
            'lease_token_hash' => $leaseHash,
        ]));

        return $statement->rowCount() === 1;
    }
}
