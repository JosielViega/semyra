<?php

declare(strict_types=1);

const ALLOWED_CANDIDATE = 'LIVE_H264_1';
const CANDIDATE_MAP_PATH = __DIR__ . '/../../../.iptv-spike-candidates.json';

$mode = $argv[2] ?? 'stream';
if (($argv[1] ?? '') !== ALLOWED_CANDIDATE || !in_array($mode, ['stream', '--check', '--probe'], true) || count($argv) > 3) {
    fwrite(STDERR, "Usage: php feed-private-iptv.php LIVE_H264_1 [--check|--probe]\n");
    exit(2);
}

try {
    $map = json_decode((string) file_get_contents(CANDIDATE_MAP_PATH), true, 32, JSON_THROW_ON_ERROR);
} catch (Throwable) {
    fwrite(STDERR, "Private candidate map is unavailable.\n");
    exit(1);
}

$sourceUrl = null;
foreach (($map['candidates'] ?? []) as $candidate) {
    if (is_array($candidate) && ($candidate['label'] ?? '') === ALLOWED_CANDIDATE) {
        $sourceUrl = is_string($candidate['url'] ?? null) ? $candidate['url'] : null;
        break;
    }
}

if ($sourceUrl === null || filter_var($sourceUrl, FILTER_VALIDATE_URL) === false) {
    fwrite(STDERR, "Authorized private candidate is unavailable.\n");
    exit(1);
}

if ($mode === '--check') {
    echo "Private candidate LIVE_H264_1: available\n";
    exit(0);
}

if (!function_exists('curl_init')) {
    fwrite(STDERR, "PHP cURL is unavailable.\n");
    exit(1);
}

$curl = curl_init($sourceUrl);
if ($curl === false) {
    fwrite(STDERR, "Private feeder could not initialize.\n");
    exit(1);
}

$probeBytes = 0;
$probeSample = '';
curl_setopt_array($curl, [
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_MAXREDIRS => 3,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_TIMEOUT => 0,
    CURLOPT_FAILONERROR => true,
    CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
    CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
    CURLOPT_USERAGENT => 'Semyra-IPTV-Technical-Spike/10A.4B',
    CURLOPT_RETURNTRANSFER => false,
    CURLOPT_HEADER => false,
    CURLOPT_NOPROGRESS => true,
    CURLOPT_WRITEFUNCTION => static function ($handle, string $bytes) use ($mode, &$probeBytes, &$probeSample): int {
        if ($mode === '--probe') {
            $remaining = 65536 - strlen($probeSample);
            if ($remaining > 0) {
                $probeSample .= substr($bytes, 0, $remaining);
            }
            $probeBytes += strlen($bytes);
            return $probeBytes >= 524288 ? 0 : strlen($bytes);
        }
        $written = @fwrite(STDOUT, $bytes);
        return $written === false ? 0 : $written;
    },
]);

$ok = curl_exec($curl);
$errorCode = curl_errno($curl);
$httpStatus = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
$contentType = strtolower((string) (curl_getinfo($curl, CURLINFO_CONTENT_TYPE) ?: 'unknown'));
curl_close($curl);
$sourceUrl = null;

if ($mode === '--probe') {
    $payload = 'unknown';
    $trimmed = ltrim($probeSample, "\xEF\xBB\xBF\r\n\t ");
    if (str_starts_with($trimmed, '#EXTM3U')) {
        $payload = 'hls-manifest';
    } elseif (strlen($probeSample) >= 12 && substr($probeSample, 4, 4) === 'ftyp') {
        $payload = 'mp4';
    } else {
        for ($offset = 0, $limit = min(188, strlen($probeSample)); $offset < $limit; ++$offset) {
            if (strlen($probeSample) > $offset + 376
                && ord($probeSample[$offset]) === 0x47
                && ord($probeSample[$offset + 188]) === 0x47
                && ord($probeSample[$offset + 376]) === 0x47) {
                $payload = 'mpeg-ts';
                break;
            }
        }
    }
    $contentClass = str_contains($contentType, 'video') || str_contains($contentType, 'octet-stream')
        ? 'media'
        : (str_contains($contentType, 'text') || str_contains($contentType, 'json') ? 'text' : 'unknown');
    echo 'Private feed bytes received: ' . $probeBytes . "\n";
    echo 'HTTP status: ' . $httpStatus . "\n";
    echo 'Content class: ' . $contentClass . "\n";
    echo 'Payload: ' . $payload . "\n";
    exit($probeBytes > 0 && $payload === 'mpeg-ts' ? 0 : 1);
}

if ($ok === false && $errorCode !== CURLE_WRITE_ERROR) {
    fwrite(STDERR, "Private feeder stream failed.\n");
    exit(1);
}
