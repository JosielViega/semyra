<?php

declare(strict_types=1);

namespace Semyra\BridgeWorker;

final class DockerMediaProcessFactory implements MediaProcessFactory
{
    public function __construct(private readonly WorkerConfig $config) {}
    public function create(Watchdog $watchdog): MediaProcess { return new DockerMediaProcess($this->config, $watchdog); }
}

final class DockerMediaProcess implements MediaProcess
{
    /** @var resource|null */
    private $helperProcess = null;
    /** @var array<int, resource> */
    private array $helperPipes = [];
    /** @var resource|null */
    private $watchdogProcess = null;
    /** @var array<int, resource> */
    private array $watchdogPipes = [];
    private string $containerName = '';
    private string $readyPath = '';
    private bool $stopping = false;
    private string $state = 'idle';
    private string $failure = 'pipeline_failed';

    public function __construct(private readonly WorkerConfig $config, private readonly Watchdog $watchdog) {}

    public function start(string $sourceUrl, string $whipEndpoint, array $job): void
    {
        $jobId = (int) ($job['job_id'] ?? 0);
        $instance = (string) ($job['transmission_instance_id'] ?? '');
        if ($this->state !== 'idle' || $jobId < 1 || preg_match('/^[a-f0-9]{32}$/', $instance) !== 1
            || strtolower((string) parse_url($whipEndpoint, PHP_URL_SCHEME)) !== 'https') {
            throw new WorkerException('pipeline_failed');
        }
        $this->containerName = 'semyra-bridge-' . $jobId . '-' . bin2hex(random_bytes(5));
        $this->readyPath = $this->config->runtimePath . DIRECTORY_SEPARATOR . 'ready-' . $this->containerName;
        $maximumAge = (string) max(3, $this->config->heartbeatSeconds * 3);
        $environment = $this->baseEnvironment() + [
            'WHIP_ENDPOINT' => $whipEndpoint,
            'SEMYRA_FEED_SOURCE_URL' => $sourceUrl,
            'SEMYRA_FEED_WATCHDOG_PATH' => $this->watchdog->path(),
            'SEMYRA_FEED_READY_PATH' => $this->readyPath,
            'SEMYRA_FEED_WATCHDOG_MAX_AGE' => $maximumAge,
            'SEMYRA_MEDIA_CONTAINER' => $this->containerName,
            'SEMYRA_MEDIA_WORKER_ID' => $this->config->workerId,
            'SEMYRA_MEDIA_JOB_ID' => (string) $jobId,
            'SEMYRA_MEDIA_INSTANCE' => $instance,
            'SEMYRA_MEDIA_DOCKER' => $this->config->dockerBinary,
            'SEMYRA_MEDIA_IMAGE' => $this->config->gstreamerImage,
        ];
        $sourceUrl = ''; $whipEndpoint = '';
        $this->helperProcess = @proc_open(
            [PHP_BINARY, dirname(__DIR__) . '/media-helper.php'],
            [0 => ['file', $this->nullDevice(), 'r'], 1 => ['file', $this->nullDevice(), 'w'], 2 => ['file', $this->nullDevice(), 'w']],
            $this->helperPipes, null, $environment, ['bypass_shell' => true],
        );
        if (!is_resource($this->helperProcess)) { throw new WorkerException('pipeline_failed'); }

        $watchdogEnvironment = $this->baseEnvironment() + [
            'SEMYRA_WATCHDOG_PATH' => $this->watchdog->path(),
            'SEMYRA_WATCHDOG_MAX_AGE' => $maximumAge,
            'SEMYRA_WATCHDOG_CONTAINER' => $this->containerName,
            'SEMYRA_WATCHDOG_DOCKER' => $this->config->dockerBinary,
        ];
        $this->watchdogProcess = @proc_open(
            [PHP_BINARY, dirname(__DIR__) . '/media-watchdog.php'],
            [0 => ['file', $this->nullDevice(), 'r'], 1 => ['file', $this->nullDevice(), 'w'], 2 => ['file', $this->nullDevice(), 'w']],
            $this->watchdogPipes, null, $watchdogEnvironment, ['bypass_shell' => true],
        );
        if (!is_resource($this->watchdogProcess)) { $this->stop(); throw new WorkerException('pipeline_failed'); }
        $this->state = 'starting';
    }

    public function poll(): string
    {
        if (!in_array($this->state, ['starting', 'running'], true)) { return $this->state; }
        if (is_file($this->readyPath) && trim((string) @file_get_contents($this->readyPath)) === 'ready') { $this->state = 'running'; }
        if (!$this->running($this->helperProcess) && !$this->stopping) {
            $this->failure = $this->state === 'starting' ? 'source_invalid' : 'source_failed';
            $this->state = 'failed';
        }
        if (!$this->running($this->watchdogProcess) && !$this->stopping) {
            $this->failure = 'pipeline_failed'; $this->state = 'failed';
        }
        return $this->state;
    }

    public function errorCode(): string { return $this->failure; }

    public function stop(): void
    {
        if ($this->stopping) { return; }
        $this->stopping = true;
        if ($this->containerName !== '') { $this->dockerCleanup('stop'); $this->dockerCleanup('kill'); $this->dockerCleanup('rm'); }
        $this->closeProcess($this->helperProcess, $this->helperPipes);
        $this->closeProcess($this->watchdogProcess, $this->watchdogPipes);
        if ($this->readyPath !== '' && is_file($this->readyPath)) { @unlink($this->readyPath); }
        $this->containerName = ''; $this->readyPath = '';
        if ($this->state !== 'failed') { $this->state = 'stopped'; }
    }

    private function running(mixed $process): bool { return is_resource($process) && (bool) (proc_get_status($process)['running'] ?? false); }

    /** @param array<int, resource> $pipes */
    private function closeProcess(mixed &$process, array &$pipes): void
    {
        foreach ($pipes as $pipe) { if (is_resource($pipe)) { @fclose($pipe); } } $pipes = [];
        if (is_resource($process)) { @proc_terminate($process); @proc_close($process); } $process = null;
    }

    /** @return list<string> */
    private function dockerCommand(int $jobId, string $instance): array
    {
        return [
            $this->config->dockerBinary, 'run', '--rm', '-i', '--name', $this->containerName,
            '--label', 'com.semyra.bridge=1', '--label', 'com.semyra.worker=' . $this->config->workerId,
            '--label', 'com.semyra.job=' . $jobId, '--label', 'com.semyra.instance=' . $instance,
            '-e', 'WHIP_ENDPOINT', $this->config->gstreamerImage, 'sh', '-lc', MediaPipeline::GSTREAMER,
        ];
    }

    /** @return array<string, string> */
    private function baseEnvironment(): array
    {
        $source = getenv(); $source = is_array($source) ? $source : [];
        $allowed = ['PATH', 'PATHEXT', 'SYSTEMROOT', 'WINDIR', 'COMSPEC', 'TEMP', 'TMP', 'HOME', 'USERPROFILE', 'HOMEDRIVE', 'HOMEPATH', 'APPDATA', 'LOCALAPPDATA', 'PROGRAMDATA', 'DOCKER_HOST', 'DOCKER_CONTEXT', 'DOCKER_CONFIG'];
        $environment = [];
        foreach ($source as $key => $value) {
            if (is_string($key) && is_string($value) && in_array(strtoupper($key), $allowed, true)) { $environment[$key] = $value; }
        }
        return $environment;
    }

    private function nullDevice(): string { return PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null'; }

    private function dockerCleanup(string $operation): void
    {
        $command = match ($operation) {
            'stop' => [$this->config->dockerBinary, 'stop', '--time', '2', $this->containerName],
            'kill' => [$this->config->dockerBinary, 'kill', $this->containerName],
            default => [$this->config->dockerBinary, 'rm', '-f', $this->containerName],
        };
        $pipes = [];
        $process = @proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $this->baseEnvironment(), ['bypass_shell' => true]);
        if (is_resource($process)) { foreach ($pipes as $pipe) { stream_get_contents($pipe); fclose($pipe); } proc_close($process); }
    }

    public function __destruct() { $this->stop(); }
}
