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
    $items = (new RoomServiceClient($apiUrl, $config['api_key'], $config['api_secret']))
        ->listParticipants(IPTV_ROOM_NAME)
        ->getParticipants();
    $ingressPresent = false;
    $viewerCount = 0;
    foreach ($items as $participant) {
        if ($participant->getIdentity() === IPTV_INGRESS_IDENTITY) {
            $ingressPresent = true;
        } else {
            $viewerCount++;
        }
    }
    echo 'Ingress participant present: ' . ($ingressPresent ? 'yes' : 'no') . "\n";
    echo 'Viewer participants: ' . $viewerCount . "\n";
} catch (Throwable) {
    fwrite(STDERR, "Room inspection: unavailable\n");
    exit(1);
}
