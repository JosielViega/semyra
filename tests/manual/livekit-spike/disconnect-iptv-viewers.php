<?php

declare(strict_types=1);

use Agence104\LiveKit\RoomServiceClient;
use function Semyra\LiveKitSpike\readPrivateEnv;
use function Semyra\LiveKitSpike\validateConfig;
use const Semyra\LiveKitSpike\IPTV_INGRESS_IDENTITY;
use const Semyra\LiveKitSpike\IPTV_ROOM_NAME;

require_once __DIR__ . '/ingress-lib.php';

try {
    $config = validateConfig(readPrivateEnv());
    $apiUrl = preg_replace('/^wss:/i', 'https:', $config['url']);
    if (!is_string($apiUrl)) {
        throw new RuntimeException('Invalid API URL.');
    }
    $client = new RoomServiceClient($apiUrl, $config['api_key'], $config['api_secret']);
    $items = $client->listParticipants(IPTV_ROOM_NAME)->getParticipants();
    $removed = 0;
    foreach ($items as $participant) {
        $identity = $participant->getIdentity();
        if ($identity !== '' && $identity !== IPTV_INGRESS_IDENTITY) {
            $client->removeParticipant(IPTV_ROOM_NAME, $identity);
            $removed++;
        }
    }
    echo 'Viewer participants disconnected: ' . $removed . "\n";
} catch (Throwable) {
    fwrite(STDERR, "Viewer cleanup: unavailable\n");
    exit(1);
}
