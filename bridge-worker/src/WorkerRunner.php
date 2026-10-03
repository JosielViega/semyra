<?php

declare(strict_types=1);

namespace Semyra\BridgeWorker;

final class WorkerRunner
{
    public function __construct(
        private readonly WorkerConfig $config,
        private readonly ControlPlaneClient $control,
        private readonly ?SourceCatalog $catalog,
        private readonly ?MediaProcessFactory $mediaFactory,
        private readonly Clock $clock,
    ) {
    }

    public function dryRun(): bool
    {
        $job = $this->claim();
        if ($job === null) {
            return false;
        }
        $this->requireKeep($this->control->heartbeat($job, 'starting'));
        $this->requireKeep($this->control->heartbeat($job, 'running'));
        $this->control->report($job, 'stopped');
        return true;
    }

    public function runOnce(): bool
    {
        $job = $this->claim();
        if ($job === null) {
            return false;
        }
        $this->process($job);
        return true;
    }

    public function runLoop(int $maximumIterations = 0): void
    {
        $iterations = 0;
        while (true) {
            if (!$this->runOnce()) {
                $this->clock->sleep($this->config->pollSeconds);
            }
            $iterations++;
            if ($maximumIterations > 0 && $iterations >= $maximumIterations) {
                return;
            }
        }
    }

    /** @return array<string, mixed>|null */
    private function claim(): ?array
    {
        $job = $this->control->claim($this->config->workerId);
        if ($job !== null) {
            $job['worker_id'] = $this->config->workerId;
        }
        return $job;
    }

    /** @param array<string, mixed> $job */
    private function process(array $job): void
    {
        if ($this->catalog === null || $this->mediaFactory === null) {
            throw new WorkerException('invalid_configuration');
        }
        $leaseSeconds = (int) ($job['lease_seconds'] ?? 0);
        $margin = $this->config->leaseSafetyMarginSeconds();
        if (!$this->config->supportsLease($leaseSeconds)) {
            $this->reportFailure($job, 'lease_invalid');
            throw new WorkerException('lease_invalid');
        }
        try {
            $sourceUrl = $this->catalog->resolve((string) ($job['source_ref'] ?? ''));
        } catch (WorkerException $exception) {
            $this->reportFailure($job, $exception->errorCode);
            throw $exception;
        }

        $watchdog = new Watchdog($this->config->runtimePath, $this->config->workerId, $this->clock);
        $media = $this->mediaFactory->create($watchdog);
        $deadline = $this->clock->now() + $leaseSeconds - $margin;
        $nextHeartbeat = $this->clock->now();
        $heartbeatStatus = 'starting';
        $failure = null;
        $lostLease = false;
        $stoppedByControl = false;

        try {
            try {
                $initial = $this->control->heartbeat($job, 'starting');
                if (($initial['action'] ?? null) === 'stop') {
                    $stoppedByControl = true;
                }
            } catch (ControlException $exception) {
                if ($exception->reason === 'lease_lost') {
                    $lostLease = true;
                } else {
                    $failure = $exception->reason === 'unavailable' ? 'control_unavailable' : 'control_rejected';
                }
            }
            if (!$lostLease && !$stoppedByControl && $failure === null) {
                $watchdog->touch();
                $deadline = $this->clock->now() + $leaseSeconds - $margin;
                $this->clock->sleep(min(2.0, max(0.5, $this->config->heartbeatSeconds / 2)));
                $media->start($sourceUrl, (string) ($job['whip_endpoint'] ?? ''), $job);
                $nextHeartbeat = $this->clock->now() + $this->config->heartbeatSeconds;
                while (true) {
                    $state = $media->poll();
                    if ($state === 'failed') {
                        $failure = $media->errorCode();
                        break;
                    }
                    if ($state === 'running') {
                        $heartbeatStatus = 'running';
                    }
                    $now = $this->clock->now();
                    if ($now >= $nextHeartbeat) {
                        try {
                            $response = $this->control->heartbeat($job, $heartbeatStatus);
                            if (($response['action'] ?? null) === 'stop') {
                                $stoppedByControl = true;
                                break;
                            }
                            $watchdog->touch();
                            $deadline = $now + $leaseSeconds - $margin;
                            $nextHeartbeat = $now + $this->config->heartbeatSeconds;
                        } catch (ControlException $exception) {
                            if ($exception->reason === 'lease_lost') {
                                $lostLease = true;
                                break;
                            }
                            if ($exception->reason !== 'unavailable') {
                                $failure = 'control_rejected';
                                break;
                            }
                            $nextHeartbeat = $now + min(1, $this->config->heartbeatSeconds);
                        }
                    }
                    if ($this->clock->now() >= $deadline) {
                        $failure = 'lease_expired';
                        break;
                    }
                    $this->clock->sleep(0.1);
                }
            }
        } catch (WorkerException $exception) {
            $failure = $exception->errorCode;
        } finally {
            $media->stop();
            $watchdog->remove();
        }

        if ($lostLease) {
            return;
        }
        if ($stoppedByControl) {
            $this->reportBestEffort($job, 'stopped');
            return;
        }
        $this->reportFailure($job, $failure ?? 'pipeline_failed');
    }

    /** @param array<string, mixed> $response */
    private function requireKeep(array $response): void
    {
        if (($response['action'] ?? null) !== 'keep') {
            throw new ControlException('rejected');
        }
    }

    /** @param array<string, mixed> $job */
    private function reportFailure(array $job, string $errorCode): void
    {
        $this->reportBestEffort($job, 'failed', $errorCode);
    }

    /** @param array<string, mixed> $job */
    private function reportBestEffort(array $job, string $status, ?string $errorCode = null): void
    {
        try {
            $this->control->report($job, $status, $errorCode);
        } catch (ControlException) {
            // The local process is already stopped; never keep media alive for reporting.
        }
    }
}
