<?php

declare(strict_types=1);

use App\Core\Database;
use App\Repositories\MediaBridgeJobRepository;
use Dotenv\Dotenv;

$root = dirname(__DIR__, 3);
require $root . '/vendor/autoload.php';
Dotenv::createImmutable($root)->safeLoad();
if (!in_array((string) env('APP_ENV', 'production'), ['local', 'testing'], true)) {
    fwrite(STDERR, "Refusing to run outside APP_ENV=local/testing.\n");
    exit(1);
}
$command = $argv[1] ?? '';
$fixturePath = __DIR__ . '/.private/semyra-room-fixture.json';
if (!is_file($fixturePath)) {
    fwrite(STDERR, "Create the local IPTV fixture first.\n");
    exit(1);
}
$fixture = json_decode((string) file_get_contents($fixturePath), true, 16, JSON_THROW_ON_ERROR);
$repository = new MediaBridgeJobRepository(new Database(require $root . '/config/database.php'));
if ($command === 'create') {
    $sourceRef = trim((string) env('MEDIA_BRIDGE_SOURCE_REF', ''));
    if (preg_match('/^[a-zA-Z0-9._:-]{1,96}$/', $sourceRef) !== 1) {
        fwrite(STDERR, "Set a private MEDIA_BRIDGE_SOURCE_REF.\n");
        exit(1);
    }
    $id = $repository->create((int) $fixture['room_id'], (string) $fixture['instance_id'], $sourceRef);
    echo 'Media bridge fixture job created: ' . $id . PHP_EOL;
    exit(0);
}
if ($command === 'stop') {
    $repository->requestStop((string) $fixture['instance_id']);
    $job = $repository->findByInstance((string) $fixture['instance_id']);
    echo 'Media bridge fixture desired state: ' . ($job['desired_state'] ?? 'missing') . PHP_EOL;
    exit(0);
}
fwrite(STDERR, "Usage: php media-bridge-job-fixture.php create|stop\n");
exit(1);
