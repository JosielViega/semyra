<?php

declare(strict_types=1);

use Livekit\IngressInput;
use function Semyra\LiveKitSpike\ingressClient;
use function Semyra\LiveKitSpike\privateIngressData;
use function Semyra\LiveKitSpike\readPrivateEnv;
use function Semyra\LiveKitSpike\validateConfig;
use function Semyra\LiveKitSpike\writePrivateIngressData;
use const Semyra\LiveKitSpike\WHIP_PRIVATE_PATH;

require_once __DIR__ . '/ingress-lib.php';

$root = dirname(__DIR__, 3);
require_once $root . '/vendor/autoload.php';
Dotenv\Dotenv::createImmutable($root)->safeLoad();
if (!in_array((string) env('APP_ENV', 'production'), ['local', 'testing'], true)) {
    fwrite(STDERR, "Refusing to run outside APP_ENV=local/testing.\n");
    exit(1);
}
if (is_file(WHIP_PRIVATE_PATH)) {
    fwrite(STDERR, "Private ingress state already exists; delete it first.\n");
    exit(1);
}
$fixturePath = __DIR__ . '/.private/semyra-room-fixture.json';
if (!is_file($fixturePath)) {
    fwrite(STDERR, "Create the local room fixture first.\n");
    exit(1);
}

try {
    $fixture = json_decode((string) file_get_contents($fixturePath), true, 16, JSON_THROW_ON_ERROR);
    $roomName = (string) ($fixture['livekit_room'] ?? '');
    $publisherIdentity = (string) ($fixture['publisher_identity'] ?? '');
    if (!preg_match('/^smy_r_[a-f0-9]{32}$/', $roomName)
        || !preg_match('/^smy_i_[a-f0-9]{32}$/', $publisherIdentity)) {
        throw new RuntimeException('Private fixture context is invalid.');
    }
    $info = ingressClient(validateConfig(readPrivateEnv()))->createIngress(
        IngressInput::WHIP_INPUT,
        'semyra_10b2_local',
        $roomName,
        $publisherIdentity,
        '',
        null,
        null,
        true,
    );
    writePrivateIngressData(privateIngressData($info));
    echo "Semyra WHIP ingress created: yes\n";
    echo "Ingress transcoding: disabled\n";
} catch (Throwable) {
    fwrite(STDERR, "Semyra WHIP ingress created: no\n");
    exit(1);
}
