<?php

declare(strict_types=1);

use Livekit\IngressInput;
use function Semyra\LiveKitSpike\ingressClient;
use function Semyra\LiveKitSpike\ingressStateLabel;
use function Semyra\LiveKitSpike\readPrivateEnv;
use function Semyra\LiveKitSpike\readPrivateIngressData;
use function Semyra\LiveKitSpike\validateConfig;

require_once __DIR__ . '/ingress-lib.php';

try {
    $private = readPrivateIngressData();
    $items = ingressClient(validateConfig(readPrivateEnv()))
        ->listIngress('', $private['ingress_id'])
        ->getItems();
    if ($items->count() !== 1) {
        throw new RuntimeException('Ingress not found.');
    }
    $info = $items[0];
    echo 'Input type: ' . ($info->getInputType() === IngressInput::WHIP_INPUT ? 'WHIP' : 'unexpected') . "\n";
    echo 'Transcoding enabled: ' . ($info->getBypassTranscoding() && !$info->getEnableTranscoding() ? 'no' : 'yes') . "\n";
    echo 'Ingress state: ' . ingressStateLabel($info) . "\n";
    echo 'Tracks: ' . ($info->getState()?->getTracks()->count() ?? 0) . "\n";
} catch (Throwable) {
    fwrite(STDERR, "Ingress inspection: unavailable\n");
    exit(1);
}
