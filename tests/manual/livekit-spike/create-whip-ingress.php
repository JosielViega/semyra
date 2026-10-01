<?php

declare(strict_types=1);

use Livekit\IngressInput;
use function Semyra\LiveKitSpike\ingressClient;
use function Semyra\LiveKitSpike\ingressStateLabel;
use function Semyra\LiveKitSpike\privateIngressData;
use function Semyra\LiveKitSpike\readPrivateEnv;
use function Semyra\LiveKitSpike\validateConfig;
use function Semyra\LiveKitSpike\writePrivateIngressData;
use const Semyra\LiveKitSpike\IPTV_INGRESS_IDENTITY;
use const Semyra\LiveKitSpike\IPTV_ROOM_NAME;
use const Semyra\LiveKitSpike\WHIP_PRIVATE_PATH;

require_once __DIR__ . '/ingress-lib.php';

if (is_file(WHIP_PRIVATE_PATH)) {
    fwrite(STDERR, "Private ingress state already exists; delete the previous ingress first.\n");
    exit(1);
}

try {
    $client = ingressClient(validateConfig(readPrivateEnv()));
    $info = $client->createIngress(
        IngressInput::WHIP_INPUT,
        'lkiptv_whip_spike',
        IPTV_ROOM_NAME,
        IPTV_INGRESS_IDENTITY,
        '',
        null,
        null,
        true,
    );
    $data = privateIngressData($info);
    writePrivateIngressData($data);

    echo "WHIP ingress created: yes\n";
    echo 'Ingress transcoding: ' . ($data['bypass_transcoding'] && !$data['enable_transcoding'] ? 'disabled' : 'enabled') . "\n";
    echo 'Ingress state: ' . ingressStateLabel($info) . "\n";
} catch (Throwable) {
    fwrite(STDERR, "WHIP ingress created: no\n");
    exit(1);
}
