<?php

declare(strict_types=1);

use Semyra\BridgeWorker\InitialMpegTsBuffer;
use Semyra\BridgeWorker\WorkerException;

require __DIR__ . '/bootstrap.php';
ini_set('display_errors', '0');
error_reporting(0);

$sourceUrl = (string) getenv('SEMYRA_FEED_SOURCE_URL');
$watchdogPath = (string) getenv('SEMYRA_FEED_WATCHDOG_PATH');
$readyPath = (string) getenv('SEMYRA_FEED_READY_PATH');
$maximumAge = (int) getenv('SEMYRA_FEED_WATCHDOG_MAX_AGE');
$parts = parse_url($sourceUrl);
if (!is_array($parts)
    || !in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)
    || trim((string) ($parts['host'] ?? '')) === ''
    || $watchdogPath === '' || $readyPath === '' || $maximumAge < 1) {
    exit(11);
}

$fresh = static function () use ($watchdogPath, $maximumAge): bool {
    $timestamp = is_file($watchdogPath) ? (int) @file_get_contents($watchdogPath) : 0;
    return $timestamp > 0 && time() - $timestamp <= $maximumAge;
};
$initial = new InitialMpegTsBuffer();
$exitCode = 11;
$announced = false;
$handle = curl_init($sourceUrl);
$sourceUrl = '';
if ($handle === false) {
    exit($exitCode);
}
curl_setopt_array($handle, [
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_MAXREDIRS => 3,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_TIMEOUT => 0,
    CURLOPT_FAILONERROR => true,
    CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
    CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
    CURLOPT_RETURNTRANSFER => false,
    CURLOPT_HEADER => false,
    CURLOPT_NOPROGRESS => false,
    CURLOPT_USERAGENT => 'Semyra-Bridge-Worker/10B.3B',
    CURLOPT_XFERINFOFUNCTION => static fn (): int => $fresh() ? 0 : 1,
    CURLOPT_WRITEFUNCTION => static function ($curl, string $bytes) use ($initial, $fresh, $readyPath, &$exitCode, &$announced): int {
        if (!$fresh()) {
            $exitCode = 12;
            return 0;
        }
        try {
            $payload = $initial->push($bytes);
        } catch (WorkerException) {
            $exitCode = 10;
            return 0;
        }
        if ($payload === null) {
            return strlen($bytes);
        }
        if (!$announced && $initial->validated()) {
            if (@file_put_contents($readyPath, 'ready', LOCK_EX) === false) {
                return 0;
            }
            $announced = true;
        }
        $length = strlen($payload);
        $offset = 0;
        while ($offset < $length) {
            if (!$fresh()) {
                $exitCode = 12;
                return 0;
            }
            $written = @fwrite(STDOUT, substr($payload, $offset, 65536));
            if ($written === false || $written === 0) {
                return 0;
            }
            $offset += $written;
        }
        return strlen($bytes);
    },
]);
curl_exec($handle);
curl_close($handle);
exit($exitCode);
