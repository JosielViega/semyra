<?php

declare(strict_types=1);

use App\Core\Database;
use App\Repositories\MediaBridgeJobRepository;
use Dotenv\Dotenv;
use Livekit\IngressInput;
use Semyra\ManagedIngressObserver\TransitionRecorder;
use function Semyra\LiveKitSpike\ingressClient;
use function Semyra\LiveKitSpike\ingressStateLabel;
use function Semyra\LiveKitSpike\readPrivateEnv;
use function Semyra\LiveKitSpike\validateConfig;

$root = dirname(__DIR__, 3);
require $root . '/vendor/autoload.php';
require_once __DIR__ . '/ingress-lib.php';
require_once __DIR__ . '/managed-ingress-observer-lib.php';
Dotenv::createImmutable($root)->safeLoad();

$duration = filter_var($argv[1] ?? 120, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 300]]);
$fixturePath = __DIR__ . '/.private/semyra-room-fixture.json';
if (PHP_SAPI !== 'cli' || $duration === false || !is_file($fixturePath)
    || !in_array((string) env('APP_ENV', 'production'), ['local', 'testing'], true)) {
    fwrite(STDERR, "Managed Ingress observer unavailable.\n");
    exit(1);
}

try {
    $fixture = json_decode((string) file_get_contents($fixturePath), true, 16, JSON_THROW_ON_ERROR);
    $repository = new MediaBridgeJobRepository(new Database(require $root . '/config/database.php'));
    $job = $repository->findByInstance((string) ($fixture['instance_id'] ?? ''));
    $expectedIngressId = trim((string) ($job['ingress_id'] ?? ''));
    if ($expectedIngressId === '') {
        throw new RuntimeException('Ingress is not assigned.');
    }

    $client = ingressClient(validateConfig(readPrivateEnv()));
    $recorder = new TransitionRecorder();
    $startedAt = microtime(true);
    do {
        $matched = null;
        foreach ($client->listIngress('', $expectedIngressId)->getItems() as $info) {
            if ($info->getIngressId() === $expectedIngressId
                && $info->getRoomName() === ($fixture['livekit_room'] ?? null)
                && $info->getParticipantIdentity() === ($fixture['publisher_identity'] ?? null)
                && $info->getInputType() === IngressInput::WHIP_INPUT) {
                $matched = $info;
                break;
            }
        }

        $state = $matched?->getState();
        $line = $recorder->record(microtime(true) - $startedAt, [
            'status' => $matched === null ? 'complete' : ingressStateLabel($matched),
            'video_mime' => $state?->getVideo()?->getMimeType(),
            'audio_mime' => $state?->getAudio()?->getMimeType(),
            'width' => $state?->getVideo()?->getWidth(),
            'height' => $state?->getVideo()?->getHeight(),
            'fps' => $state?->getVideo()?->getFramerate(),
        ]);
        if ($line !== null) {
            fwrite(STDOUT, $line . PHP_EOL);
        }
        if ($matched === null) {
            break;
        }
        usleep(1_000_000);
    } while (microtime(true) - $startedAt < $duration);
} catch (Throwable) {
    fwrite(STDERR, "Managed Ingress observer unavailable.\n");
    exit(1);
}
