<?php

declare(strict_types=1);

$source = __DIR__ . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR;
foreach ([
    'WorkerException.php',
    'ControlException.php',
    'Clock.php',
    'WorkerConfig.php',
    'WorkerLock.php',
    'Watchdog.php',
    'SourceCatalog.php',
    'MpegTsValidator.php',
    'InitialMpegTsBuffer.php',
    'ControlPlaneClient.php',
    'MediaProcess.php',
    'MediaPipeline.php',
    'MediaExitStatus.php',
    'DockerEnvironmentCheck.php',
    'DockerMediaProcess.php',
    'WorkerRunner.php',
] as $file) {
    require_once $source . $file;
}
