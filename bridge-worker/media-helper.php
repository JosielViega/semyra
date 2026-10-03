<?php

declare(strict_types=1);

use Semyra\BridgeWorker\MediaPipeline;
use Semyra\BridgeWorker\MediaExitStatus;
use Semyra\BridgeWorker\WorkerConfig;

require __DIR__ . '/bootstrap.php';
ini_set('display_errors', '0');
error_reporting(0);

$container = (string) getenv('SEMYRA_MEDIA_CONTAINER');
$workerId = (string) getenv('SEMYRA_MEDIA_WORKER_ID');
$jobId = (string) getenv('SEMYRA_MEDIA_JOB_ID');
$instance = (string) getenv('SEMYRA_MEDIA_INSTANCE');
$docker = (string) getenv('SEMYRA_MEDIA_DOCKER');
$image = (string) getenv('SEMYRA_MEDIA_IMAGE');
$statusPath = (string) getenv('SEMYRA_MEDIA_STATUS_PATH');
$whip = (string) getenv('WHIP_ENDPOINT');
if (preg_match('/^semyra-bridge-[1-9][0-9]*-[a-f0-9]{10}$/', $container) !== 1
    || preg_match('/^wrk_[a-f0-9]{32}$/', $workerId) !== 1
    || preg_match('/^[1-9][0-9]*$/', $jobId) !== 1
    || preg_match('/^[a-f0-9]{32}$/', $instance) !== 1
    || preg_match('#^[A-Za-z0-9._:/\\\\-]+$#', $docker) !== 1
    || $image !== WorkerConfig::IMAGE
    || $statusPath === ''
    || strtolower((string) parse_url($whip, PHP_URL_SCHEME)) !== 'https') {
    exit(20);
}
@unlink($statusPath);

$command = [
    $docker, 'run', '--rm', '-i', '--name', $container,
    '--label', 'com.semyra.bridge=1', '--label', 'com.semyra.worker=' . $workerId,
    '--label', 'com.semyra.job=' . $jobId, '--label', 'com.semyra.instance=' . $instance,
    '-e', 'WHIP_ENDPOINT', $image, 'sh', '-lc', MediaPipeline::GSTREAMER,
];
$dockerEnvironment = [];
$allowedEnvironment = ['PATH', 'PATHEXT', 'SYSTEMROOT', 'WINDIR', 'COMSPEC', 'TEMP', 'TMP', 'HOME', 'USERPROFILE', 'HOMEDRIVE', 'HOMEPATH', 'APPDATA', 'LOCALAPPDATA', 'PROGRAMDATA', 'DOCKER_HOST', 'DOCKER_CONTEXT', 'DOCKER_CONFIG'];
foreach (getenv() as $key => $value) {
    if (is_string($key) && is_string($value) && in_array(strtoupper($key), $allowedEnvironment, true)) {
        $dockerEnvironment[$key] = $value;
    }
}
$dockerEnvironment['WHIP_ENDPOINT'] = $whip;
$whip = '';
$dockerProcess = @proc_open(
    $command,
    [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
    $dockerPipes, null, $dockerEnvironment, ['bypass_shell' => true],
);
if (!is_resource($dockerProcess)) {
    MediaExitStatus::write($statusPath, MediaExitStatus::HELPER_FAILED);
    exit(21);
}
stream_set_blocking($dockerPipes[1], false);
stream_set_blocking($dockerPipes[2], false);

$feederProcess = @proc_open(
    [PHP_BINARY, __DIR__ . '/feeder.php'],
    [0 => ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'r'], 1 => $dockerPipes[0], 2 => ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'w']],
    $feederPipes, null, getenv(), ['bypass_shell' => true],
);
if (!is_resource($feederProcess)) {
    MediaExitStatus::write($statusPath, MediaExitStatus::HELPER_FAILED);
    @proc_terminate($dockerProcess);
    exit(22);
}

$reason = MediaExitStatus::HELPER_FAILED;
while (true) {
    foreach ([1, 2] as $index) { if (isset($dockerPipes[$index])) { stream_get_contents($dockerPipes[$index], 8192); } }
    $feederStatus = proc_get_status($feederProcess);
    $dockerStatus = proc_get_status($dockerProcess);
    $sourceRunning = (bool) ($feederStatus['running'] ?? false);
    $pipelineRunning = (bool) ($dockerStatus['running'] ?? false);
    if (!$sourceRunning || !$pipelineRunning) {
        $reason = MediaExitStatus::classify($sourceRunning, $pipelineRunning);
        break;
    }
    usleep(100_000);
}
MediaExitStatus::write($statusPath, $reason);
@proc_terminate($feederProcess);
@proc_terminate($dockerProcess);
foreach ($dockerPipes as $pipe) { if (is_resource($pipe)) { @fclose($pipe); } }
@proc_close($feederProcess);
@proc_close($dockerProcess);
exit(23);
