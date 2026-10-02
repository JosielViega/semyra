<?php

declare(strict_types=1);

use Agence104\LiveKit\RoomServiceClient;
use function Semyra\LiveKitSpike\readPrivateEnv;
use function Semyra\LiveKitSpike\validateConfig;

require_once __DIR__ . '/ingress-lib.php';

try {
    $fixture = json_decode(
        (string) file_get_contents(__DIR__ . '/.private/semyra-room-fixture.json'),
        true,
        16,
        JSON_THROW_ON_ERROR,
    );
    $config = validateConfig(readPrivateEnv());
    $apiUrl = preg_replace('/^wss:/i', 'https:', $config['url']);
    $client = new RoomServiceClient($apiUrl, $config['api_key'], $config['api_secret']);
    $participants = $client->listParticipants((string) $fixture['livekit_room'])->getParticipants();
    $removed = 0;
    foreach ($participants as $participant) {
        $identity = $participant->getIdentity();
        if (str_starts_with($identity, 'smy_v_')) {
            $client->removeParticipant((string) $fixture['livekit_room'], $identity);
            ++$removed;
        }
    }
    echo "Viewer participants disconnected: {$removed}\n";
} catch (Throwable) {
    fwrite(STDERR, "Viewer cleanup: unavailable\n");
    exit(1);
}
