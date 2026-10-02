<?php

declare(strict_types=1);

use App\Core\Database;
use App\Repositories\RoomTransmissionRepository;
use App\Services\LiveKitRoomContext;
use Dotenv\Dotenv;

const FIXTURE_PATH = __DIR__ . '/.private/semyra-room-fixture.json';

$root = dirname(__DIR__, 3);
require $root . '/vendor/autoload.php';
Dotenv::createImmutable($root)->safeLoad();

$environment = (string) env('APP_ENV', 'production');
if (!in_array($environment, ['local', 'testing'], true)) {
    fwrite(STDERR, "Refusing to run outside APP_ENV=local/testing.\n");
    exit(1);
}

$command = $argv[1] ?? '';
if (!in_array($command, ['start', 'end'], true)) {
    fwrite(STDERR, "Usage: php semyra-room-fixture.php start ROOM_CODE | end\n");
    exit(1);
}

$database = new Database(require $root . '/config/database.php');
$pdo = $database->connection();
$repository = new RoomTransmissionRepository($database);

if ($command === 'start') {
    $code = strtoupper(trim((string) ($argv[2] ?? '')));
    if (preg_match('/^[A-Z0-9]{8}$/', $code) !== 1) {
        fwrite(STDERR, "A valid eight-character local room code is required.\n");
        exit(1);
    }
    $statement = $pdo->prepare('SELECT id FROM rooms WHERE code = :code LIMIT 1');
    $statement->execute(['code' => $code]);
    $roomId = $statement->fetchColumn();
    if (!is_numeric($roomId)) {
        fwrite(STDERR, "Local room was not found.\n");
        exit(1);
    }
    $ownerHash = hash('sha256', 'semyra-10b2-local-fixture');
    $repository->startOrReplace((int) $roomId, $ownerHash, 'iptv', null, 'live');
    $transmission = $repository->findByRoom((int) $roomId);
    if ($transmission === null || ($transmission['source_type'] ?? null) !== 'iptv') {
        throw new RuntimeException('Could not create local IPTV fixture.');
    }
    $revision = (int) $transmission['revision'];
    $startedAt = (string) $transmission['started_at'];
    $context = new LiveKitRoomContext((string) env('LIVEKIT_NAMESPACE', $environment));
    $fixture = [
        'room_id' => (int) $roomId,
        'room_code' => $code,
        'revision' => $revision,
        'started_at' => $startedAt,
        'owner_hash' => $ownerHash,
        'livekit_room' => $context->roomName((int) $roomId),
        'publisher_identity' => $context->publisherIdentity((int) $roomId, $revision, $startedAt),
    ];
    if (file_put_contents(FIXTURE_PATH, json_encode($fixture, JSON_THROW_ON_ERROR), LOCK_EX) === false) {
        throw new RuntimeException('Could not write private fixture state.');
    }
    echo "Local IPTV fixture created: yes\n";
    echo "Transmission revision: {$revision}\n";
    exit(0);
}

if (!is_file(FIXTURE_PATH)) {
    echo "Local IPTV fixture present: no\n";
    exit(0);
}
$fixture = json_decode((string) file_get_contents(FIXTURE_PATH), true, 16, JSON_THROW_ON_ERROR);
$statement = $pdo->prepare(
    'DELETE FROM room_transmissions WHERE room_id = :room_id AND revision = :revision '
    . 'AND started_at = :started_at AND source_type = \'iptv\' '
    . 'AND owner_participant_key_hash = :owner_hash'
);
$statement->execute([
    'room_id' => $fixture['room_id'],
    'revision' => $fixture['revision'],
    'started_at' => $fixture['started_at'],
    'owner_hash' => $fixture['owner_hash'],
]);
unlink(FIXTURE_PATH);
echo 'Local IPTV fixture removed: ' . ($statement->rowCount() === 1 ? 'yes' : 'not-current') . "\n";
