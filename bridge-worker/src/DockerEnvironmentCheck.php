<?php

declare(strict_types=1);

namespace Semyra\BridgeWorker;

final class DockerEnvironmentCheck
{
    public function __construct(private readonly WorkerConfig $config)
    {
    }

    public function run(): void
    {
        $version = $this->command([$this->config->dockerBinary, 'version', '--format', '{{.Server.Version}}'], 10);
        if ($version === '') {
            throw new WorkerException('docker_unavailable');
        }
        $digests = $this->command([
            $this->config->dockerBinary,
            'image',
            'inspect',
            '--format',
            '{{json .RepoDigests}}',
            $this->config->gstreamerImage,
        ], 10);
        if (!str_contains($digests, '@' . WorkerConfig::IMAGE_DIGEST)) {
            throw new WorkerException('docker_image_mismatch');
        }
        $plugins = [
            'whipsink', 'tsdemux', 'h264parse', 'rtph264pay', 'aacparse', 'avdec_aac',
            'audioconvert', 'audioresample', 'opusenc', 'rtpopuspay',
        ];
        $script = 'command -v gst-launch-1.0 >/dev/null';
        foreach ($plugins as $plugin) {
            $script .= ' && gst-inspect-1.0 ' . $plugin . ' >/dev/null';
        }
        $this->command([
            $this->config->dockerBinary, 'run', '--rm', $this->config->gstreamerImage, 'sh', '-lc', $script,
        ], 30);
    }

    private function command(array $command, int $timeout): string
    {
        $pipes = [];
        $process = @proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, null, ['bypass_shell' => true]);
        if (!is_resource($process)) {
            throw new WorkerException('docker_unavailable');
        }
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $started = microtime(true);
        $output = '';
        do {
            $output .= (string) stream_get_contents($pipes[1], 8192);
            $status = proc_get_status($process);
            if (!$status['running']) {
                break;
            }
            if (microtime(true) - $started >= $timeout) {
                proc_terminate($process, 9);
                break;
            }
            usleep(50_000);
        } while (true);
        foreach ($pipes as $pipe) {
            fclose($pipe);
        }
        $exitCode = proc_close($process);
        if ($exitCode !== 0 && ($status['exitcode'] ?? -1) !== 0) {
            throw new WorkerException('docker_unavailable');
        }

        return trim($output);
    }
}
