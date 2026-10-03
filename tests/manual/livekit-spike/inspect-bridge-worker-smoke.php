<?php

declare(strict_types=1);

use Livekit\IngressInput;
use function Semyra\LiveKitSpike\ingressClient;
use function Semyra\LiveKitSpike\ingressStateLabel;
use function Semyra\LiveKitSpike\readPrivateEnv;
use function Semyra\LiveKitSpike\validateConfig;

require_once __DIR__ . '/ingress-lib.php';

try {
    $fixture = json_decode((string) file_get_contents(__DIR__ . '/.private/semyra-room-fixture.json'), true, 16, JSON_THROW_ON_ERROR);
    $matches = [];
    foreach (ingressClient(validateConfig(readPrivateEnv()))->listIngress((string) $fixture['livekit_room'])->getItems() as $info) {
        if ($info->getRoomName() === $fixture['livekit_room']
            && $info->getParticipantIdentity() === $fixture['publisher_identity']
            && $info->getInputType() === IngressInput::WHIP_INPUT) {
            $matches[] = $info;
        }
    }
    echo 'Owned smoke ingresses: ' . count($matches) . PHP_EOL;
    if (count($matches) !== 1) {
        exit(1);
    }
    $info = $matches[0];
    $state = $info->getState();
    echo 'Ingress state: ' . ingressStateLabel($info) . PHP_EOL;
    echo 'Bypass transcoding: ' . ($info->getBypassTranscoding() ? 'yes' : 'no') . PHP_EOL;
    echo 'Video codec: ' . ($state?->getVideo()?->getMimeType() ?: 'not-observed') . PHP_EOL;
    echo 'Video size: ' . ($state?->getVideo() ? $state->getVideo()->getWidth() . 'x' . $state->getVideo()->getHeight() : 'not-observed') . PHP_EOL;
    echo 'Video FPS: ' . ($state?->getVideo()?->getFramerate() ?: 'not-observed') . PHP_EOL;
    echo 'Audio codec: ' . ($state?->getAudio()?->getMimeType() ?: 'not-observed') . PHP_EOL;
    echo 'Published tracks: ' . count($state?->getTracks() ?? []) . PHP_EOL;
} catch (Throwable) {
    fwrite(STDERR, "Bridge worker smoke inspection: unavailable\n");
    exit(1);
}
