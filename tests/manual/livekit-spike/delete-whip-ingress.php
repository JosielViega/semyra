<?php

declare(strict_types=1);

use function Semyra\LiveKitSpike\ingressClient;
use function Semyra\LiveKitSpike\readPrivateEnv;
use function Semyra\LiveKitSpike\readPrivateIngressData;
use function Semyra\LiveKitSpike\removePrivateIngressData;
use function Semyra\LiveKitSpike\validateConfig;

require_once __DIR__ . '/ingress-lib.php';

try {
    $private = readPrivateIngressData();
    ingressClient(validateConfig(readPrivateEnv()))->deleteIngress($private['ingress_id']);
    removePrivateIngressData();
    echo "WHIP ingress deleted: yes\n";
} catch (Throwable) {
    fwrite(STDERR, "WHIP ingress deleted: no\n");
    exit(1);
}
