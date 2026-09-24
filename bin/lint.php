<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$directories = ['app', 'bin', 'bootstrap', 'config', 'public', 'resources', 'routes', 'tests'];
$failed = false;
$count = 0;

foreach ($directories as $directory) {
    $path = $root . DIRECTORY_SEPARATOR . $directory;
    if (!is_dir($path)) {
        continue;
    }

    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path));
    foreach ($iterator as $file) {
        if (!$file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        ++$count;
        $command = escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($file->getPathname());
        exec($command, $output, $exitCode);
        if ($exitCode !== 0) {
            $failed = true;
            fwrite(STDERR, implode(PHP_EOL, $output) . PHP_EOL);
        }
        $output = [];
    }
}

if ($failed) {
    exit(1);
}

fwrite(STDOUT, "PHP syntax valid in {$count} project files." . PHP_EOL);
