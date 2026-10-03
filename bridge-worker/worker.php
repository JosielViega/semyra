<?php

declare(strict_types=1);

use Semyra\BridgeWorker\DockerEnvironmentCheck;
use Semyra\BridgeWorker\DockerMediaProcessFactory;
use Semyra\BridgeWorker\HttpControlPlaneClient;
use Semyra\BridgeWorker\SourceCatalog;
use Semyra\BridgeWorker\SystemClock;
use Semyra\BridgeWorker\WorkerConfig;
use Semyra\BridgeWorker\WorkerException;
use Semyra\BridgeWorker\WorkerLock;
use Semyra\BridgeWorker\WorkerRunner;

require __DIR__ . '/bootstrap.php';

$modes = ['--check', '--dry-run', '--once', '--loop'];
$selected = array_values(array_intersect($argv ?? [], $modes));
if (PHP_SAPI !== 'cli' || count($selected) !== 1 || count($argv) !== 2) {
    fwrite(STDERR, "Usage: php bridge-worker/worker.php --check|--dry-run|--once|--loop\n");
    exit(1);
}

$mode = $selected[0];
$lock = new WorkerLock();
try {
    $environment = getenv();
    $config = WorkerConfig::fromEnvironment(is_array($environment) ? $environment : [], __DIR__, $mode !== '--dry-run');
    $lock->acquire($config->runtimePath, $config->workerId);
    $clock = new SystemClock();

    if ($mode === '--check') {
        new SourceCatalog($config->catalogPath);
        (new DockerEnvironmentCheck($config))->run();
        echo "bridge check: passed\n";
        exit(0);
    }

    $control = new HttpControlPlaneClient($config->controlUrl, $config->workerSecret);
    $catalog = $mode === '--dry-run' ? null : new SourceCatalog($config->catalogPath);
    $factory = $mode === '--dry-run' ? null : new DockerMediaProcessFactory($config);
    $runner = new WorkerRunner($config, $control, $catalog, $factory, $clock);
    if ($mode === '--dry-run') {
        if ($runner->dryRun()) {
            echo "whip credentials received: yes\nbridge dry-run: passed\n";
        } else {
            echo "bridge dry-run: no job\n";
        }
    } elseif ($mode === '--once') {
        echo $runner->runOnce() ? "bridge once: completed\n" : "bridge once: no job\n";
    } else {
        $runner->runLoop();
    }
} catch (WorkerException $exception) {
    fwrite(STDERR, 'Bridge worker failed: ' . $exception->errorCode . ".\n");
    exit(1);
} catch (Throwable) {
    fwrite(STDERR, "Bridge worker failed: internal_error.\n");
    exit(1);
} finally {
    $lock->release();
}
