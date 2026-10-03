<?php

declare(strict_types=1);

ini_set('display_errors', '0');
error_reporting(0);

$path = (string) getenv('SEMYRA_WATCHDOG_PATH');
$maximumAge = (int) getenv('SEMYRA_WATCHDOG_MAX_AGE');
$container = (string) getenv('SEMYRA_WATCHDOG_CONTAINER');
$docker = (string) getenv('SEMYRA_WATCHDOG_DOCKER');
if ($path === '' || $maximumAge < 1
    || preg_match('/^semyra-bridge-[1-9][0-9]*-[a-f0-9]{10}$/', $container) !== 1
    || preg_match('#^[A-Za-z0-9._:/\\\\-]+$#', $docker) !== 1) {
    exit(2);
}

while (true) {
    $timestamp = is_file($path) ? (int) @file_get_contents($path) : 0;
    $now = time();
    if ($timestamp < 1 || $timestamp > $now || $now - $timestamp >= $maximumAge) {
        foreach ([
            [$docker, 'kill', $container],
            [$docker, 'rm', '-f', $container],
        ] as $command) {
            $pipes = [];
            $process = @proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, null, ['bypass_shell' => true]);
            if (is_resource($process)) {
                foreach ($pipes as $pipe) { stream_get_contents($pipe); fclose($pipe); }
                proc_close($process);
            }
        }
        exit(0);
    }
    sleep(1);
}
