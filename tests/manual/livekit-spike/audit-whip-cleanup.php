<?php

declare(strict_types=1);

use function Semyra\LiveKitSpike\ingressClient;
use function Semyra\LiveKitSpike\readPrivateEnv;
use function Semyra\LiveKitSpike\validateConfig;
use const Semyra\LiveKitSpike\IPTV_ROOM_NAME;

require_once __DIR__ . '/ingress-lib.php';

try {
    $items = ingressClient(validateConfig(readPrivateEnv()))->listIngress()->getItems();
    $remaining = 0;
    foreach ($items as $info) {
        if ($info->getRoomName() === IPTV_ROOM_NAME || $info->getName() === 'lkiptv_whip_spike') {
            $remaining++;
        }
    }
    echo 'Temporary WHIP ingresses remaining: ' . $remaining . "\n";
    exit($remaining === 0 ? 0 : 1);
} catch (Throwable) {
    fwrite(STDERR, "Ingress cleanup audit: unavailable\n");
    exit(1);
}
