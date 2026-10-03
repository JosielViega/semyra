<?php

declare(strict_types=1);

use App\Core\Database;
use App\Repositories\RoomRepository;
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
$rooms = new RoomRepository($database);
$repository = new RoomTransmissionRepository($database);

if ($command === 'start') {
    $code = strtoupper(trim((string) ($argv[2] ?? '')));
    if (preg_match('/^[A-Z0-9]{8}$/', $code) !== 1) {
        fwrite(STDERR, "A valid eight-character local room code is required.\n");
        exit(1);
    }
    $room = $rooms->findByCode($code);
    if ($room === null) {
        fwrite(STDERR, "Local room is unavailable or expired.\n");
        exit(1);
    }
    $roomId = (int) $room['id'];
    $rooms->touchActivity($roomId);
    $revalidatedRoom = $rooms->findByCode($code);
    if ($revalidatedRoom === null || (int) $revalidatedRoom['id'] !== $roomId) {
        fwrite(STDERR, "Local room is unavailable or expired.\n");
        exit(1);
    }
    $ownerHash = hash('sha256', 'semyra-10b2-local-fixture');
    $repository->startOrReplace($roomId, $ownerHash, 'iptv', null, 'live');
    $transmission = $repository->findByRoom($roomId);
    if ($transmission === null || ($transmission['source_type'] ?? null) !== 'iptv') {
        throw new RuntimeException('Could not create local IPTV fixture.');
    }
    $revision = (int) $transmission['revision'];
    $instanceId = (string) $transmission['instance_id'];
    $context = new LiveKitRoomContext((string) env('LIVEKIT_NAMESPACE', $environment));
    $fixture = [
        'room_id' => $roomId,
        'room_code' => $code,
        'revision' => $revision,
        'instance_id' => $instanceId,
        'owner_hash' => $ownerHash,
        'livekit_room' => $context->roomName($roomId),
        'publisher_identity' => $context->publisherIdentity($roomId, $instanceId),
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
    'DELETE FROM room_transmissions WHERE room_id = :room_id AND instance_id = :instance_id '
    . 'AND revision = :revision AND source_type = \'iptv\' '
    . 'AND owner_participant_key_hash = :owner_hash'
);
$statement->execute([
    'room_id' => $fixture['room_id'],
    'instance_id' => $fixture['instance_id'],
    'revision' => $fixture['revision'],
    'owner_hash' => $fixture['owner_hash'],
]);
unlink(FIXTURE_PATH);
echo 'Local IPTV fixture removed: ' . ($statement->rowCount() === 1 ? 'yes' : 'not-current') . "\n";
