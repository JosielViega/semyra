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
    $participants = (new RoomServiceClient($apiUrl, $config['api_key'], $config['api_secret']))
        ->listParticipants((string) $fixture['livekit_room'])
        ->getParticipants();
    $publisherPresent = false;
    $viewerIdentities = [];
    foreach ($participants as $participant) {
        $identity = $participant->getIdentity();
        if ($identity === $fixture['publisher_identity']) {
            $publisherPresent = true;
        } elseif (str_starts_with($identity, 'smy_v_')) {
            $viewerIdentities[$identity] = true;
        }
    }
    echo 'Expected publisher present: ' . ($publisherPresent ? 'yes' : 'no') . "\n";
    echo 'Viewer participants: ' . count($viewerIdentities) . "\n";
    echo 'Viewer identities distinct: ' . (count($viewerIdentities) >= 2 ? 'yes' : 'not-observed') . "\n";
} catch (Throwable) {
    fwrite(STDERR, "Semyra room inspection: unavailable\n");
    exit(1);
}
