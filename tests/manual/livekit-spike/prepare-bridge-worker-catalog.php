<?php

declare(strict_types=1);

const BRIDGE_SOURCE_REF = 'live_h264_primary';
const BRIDGE_ALLOWED_CANDIDATE = 'LIVE_H264_1';

$root = dirname(__DIR__, 3);
$candidatePath = $root . '/.iptv-spike-candidates.json';
$catalogPath = $root . '/bridge-worker/.private/source-catalog.json';
try {
    $map = json_decode((string) file_get_contents($candidatePath), true, 32, JSON_THROW_ON_ERROR);
    $url = null;
    foreach (($map['candidates'] ?? []) as $candidate) {
        if (is_array($candidate) && ($candidate['label'] ?? null) === BRIDGE_ALLOWED_CANDIDATE) {
            $url = is_string($candidate['url'] ?? null) ? $candidate['url'] : null;
            break;
        }
    }
    $parts = is_string($url) ? parse_url($url) : false;
    if (!is_array($parts) || !in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)) {
        throw new RuntimeException('Authorized candidate is unavailable.');
    }
    $directory = dirname($catalogPath);
    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
        throw new RuntimeException('Private worker directory is unavailable.');
    }
    $catalog = ['version' => 1, 'sources' => [BRIDGE_SOURCE_REF => ['type' => 'http_mpegts', 'url' => $url]]];
    file_put_contents($catalogPath, json_encode($catalog, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL, LOCK_EX);
    echo "Private bridge catalog prepared: yes\n";
} catch (Throwable) {
    fwrite(STDERR, "Private bridge catalog preparation failed.\n");
    exit(1);
}
