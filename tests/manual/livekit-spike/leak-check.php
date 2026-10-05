<?php

declare(strict_types=1);

use function Semyra\LiveKitSpike\readPrivateEnv;

require_once __DIR__ . '/lib.php';

$root = dirname(__DIR__, 3);
$privateValues = readPrivateEnv();
$sensitiveValues = array_filter([
    trim($privateValues['LIVEKIT_URL'] ?? ''),
    trim($privateValues['LIVEKIT_API_KEY'] ?? ''),
    trim($privateValues['LIVEKIT_API_SECRET'] ?? ''),
], static fn (string $value): bool => $value !== '');

$liveKitUrl = trim($privateValues['LIVEKIT_URL'] ?? '');
if ($liveKitUrl !== '') {
    $liveKitHost = (string) parse_url($liveKitUrl, PHP_URL_HOST);
    if ($liveKitHost !== '') {
        $sensitiveValues[] = $liveKitHost;
    }
}

$candidateMapPath = $root . '/.iptv-spike-candidates.json';
if (is_file($candidateMapPath)) {
    try {
        $candidateMap = json_decode((string) file_get_contents($candidateMapPath), true, 64, JSON_THROW_ON_ERROR);
        foreach (($candidateMap['candidates'] ?? []) as $candidate) {
            $url = is_array($candidate) ? trim((string) ($candidate['url'] ?? '')) : '';
            if ($url === '') {
                continue;
            }
            $sensitiveValues[] = $url;
            $parts = parse_url($url);
            if (is_array($parts)) {
                foreach (['host', 'user', 'pass'] as $part) {
                    $value = trim((string) ($parts[$part] ?? ''));
                    if ($value !== '') {
                        $sensitiveValues[] = $value;
                    }
                }
                parse_str((string) ($parts['query'] ?? ''), $query);
                array_walk_recursive($query, static function (mixed $value) use (&$sensitiveValues): void {
                    if (is_scalar($value) && trim((string) $value) !== '') {
                        $sensitiveValues[] = trim((string) $value);
                    }
                });
            }
        }
    } catch (Throwable) {
        fwrite(STDERR, "Consolidated leak check: FAILED\n");
        exit(1);
    }
}

$whipPrivatePath = __DIR__ . '/.private/whip-ingress.json';
if (is_file($whipPrivatePath)) {
    try {
        $whipPrivate = json_decode((string) file_get_contents($whipPrivatePath), true, 16, JSON_THROW_ON_ERROR);
        foreach (['endpoint', 'ingress_id'] as $field) {
            $value = trim((string) ($whipPrivate[$field] ?? ''));
            if ($value !== '') {
                $sensitiveValues[] = $value;
            }
        }
    } catch (Throwable) {
        fwrite(STDERR, "Consolidated leak check: FAILED\n");
        exit(1);
    }
}

$sensitiveValues = array_values(array_unique(array_filter(
    $sensitiveValues,
    static fn (string $value): bool => strlen($value) >= 6,
)));

$paths = [];
$authorRoots = [
    $root . '/tests/manual/iptv-spike',
    $root . '/tests/manual/livekit-spike',
    $root . '/tests',
    $root . '/bridge-worker',
    $root . '/deploy/bridge-worker',
    $root . '/deploy/hostgator/mirror',
    $root . '/docs',
    $root . '/desktop',
    $root . '/.github/workflows',
    $root . '/public/assets/js',
    $root . '/resources/views/layouts',
];

foreach ($authorRoots as $authorRoot) {
    if (!is_dir($authorRoot)) {
        continue;
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($authorRoot, FilesystemIterator::SKIP_DOTS),
    );
    foreach ($iterator as $file) {
        if (!$file->isFile()) {
            continue;
        }

        $normalized = str_replace('\\', '/', $file->getPathname());
        if (
            str_contains($normalized, '/.private/')
            || str_contains($normalized, '/vendor/')
            || str_contains($normalized, '/vendor-js/')
            || str_contains($normalized, '/bin/')
            || str_contains($normalized, '/obj/')
            || str_contains($normalized, '/.vs/')
            || str_contains($normalized, '/TestResults/')
        ) {
            continue;
        }
        $paths[] = $file->getPathname();
    }
}

$content = '';
foreach (array_unique($paths) as $path) {
    if (is_file($path)) {
        $content .= (string) file_get_contents($path);
    }
}

$gitDiff = shell_exec('git -C ' . escapeshellarg($root) . ' diff --no-ext-diff 2>NUL') ?: '';
$content .= $gitDiff;
$leaked = preg_match('/\beyJ[A-Za-z0-9_-]{20,}\.[A-Za-z0-9_-]{20,}\.[A-Za-z0-9_-]{20,}\b/', $content) === 1
    || preg_match('~https?://[^\s/:@]+:[^\s/@]+@~i', $content) === 1;

foreach ($sensitiveValues as $value) {
    if (str_contains($content, $value) || str_contains($content, rawurlencode($value))) {
        $leaked = true;
        break;
    }
}

if ($leaked) {
    fwrite(STDERR, "Consolidated leak check: FAILED\n");
    exit(1);
}

fwrite(STDOUT, "Consolidated leak check: PASSED\n");
