<?php

declare(strict_types=1);

const MAX_NETWORK_BYTES = 65536;
const MAX_CANDIDATES_PER_KIND = 3;
const SENSITIVE_QUERY_KEYS = ['username', 'user', 'password', 'pass', 'token', 'auth', 'key'];

$root = dirname(__DIR__, 3);
$playlistPath = $root . '/playlist_venlomxo1402_plus.m3u';
$reportPath = __DIR__ . '/local-playlist-report.txt';
$candidateMapPath = $root . '/.iptv-spike-candidates.json';

if (!is_file($playlistPath)) {
    fwrite(STDERR, "Private playlist not found.\n");
    exit(1);
}

/** @var array<string, true> $secrets */
$secrets = [];
/** @var array<string, true> $rawHosts */
$rawHosts = [];

function registerSecret(array &$secrets, string $value): void
{
    $value = trim($value);
    if ($value !== '' && strlen($value) >= 3 && count($secrets) < 1000) {
        $secrets[$value] = true;
    }
}

function extensionBucket(string $path): string
{
    $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    return match ($extension) {
        'm3u8', 'ts', 'mp4', 'mkv', 'avi', 'm4v', 'webm' => $extension,
        '' => 'none',
        default => 'other',
    };
}

function candidateScore(string $metadata): int
{
    $score = 0;
    if (preg_match('/\b(?:H265|HEVC)\b/i', $metadata) !== 1) {
        $score += 100;
    }
    if (preg_match('/\b(?:FHD|HD)\b/i', $metadata) === 1) {
        $score += 20;
    }
    if (preg_match('/\bH264\b/i', $metadata) === 1) {
        $score += 10;
    }
    return $score;
}

function groupKind(string $group): ?string
{
    $normalized = mb_strtoupper($group, 'UTF-8');
    if (preg_match('/\b(?:LIVE|AO VIVO|CANAIS|TV ABERTA|ABERTOS|NOTICIAS|ESPORTES|24H)\b/u', $normalized) === 1) {
        return 'live';
    }
    if (preg_match('/\b(?:FILME|FILMES|MOVIE|MOVIES|VOD)\b/u', $normalized) === 1) {
        return 'movie';
    }
    if (preg_match('/\b(?:SERIE|SERIES|NOVELA|NOVELAS|DORAMA|DORAMAS|ANIME|ANIMES)\b/u', $normalized) === 1) {
        return 'series';
    }
    return null;
}

function looksLikeCredentialSegment(string $segment): bool
{
    $segment = rawurldecode(trim($segment));
    if (strlen($segment) < 8 || preg_match('/\s/', $segment) === 1) {
        return false;
    }

    return preg_match('/[A-Za-z]/', $segment) === 1
        && preg_match('/[0-9]/', $segment) === 1;
}

function offerCandidate(array &$pool, array $candidate): void
{
    $pool[] = $candidate;
    usort($pool, static fn (array $left, array $right): int => $right['score'] <=> $left['score']);
    if (count($pool) > 60) {
        array_pop($pool);
    }
}

function safeGroupName(string $name, array $secrets): ?string
{
    $name = trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $name) ?? '');
    if ($name === '' || preg_match('~https?://|(?:username|user|password|pass|token|auth|key)\s*[=:]~i', $name) === 1) {
        return null;
    }
    foreach (array_keys($secrets) as $secret) {
        if ($secret !== '' && str_contains($name, $secret)) {
            return null;
        }
    }
    return mb_substr($name, 0, 100);
}

function safeScalar(string $value, array $secrets): string
{
    $value = preg_replace('~https?://\S+~i', '[redacted-url]', $value) ?? '';
    $value = preg_replace('/([?&]?(?:username|user|password|pass|token|auth|key)=)[^&\s]*/i', '$1***', $value) ?? '';
    foreach (array_keys($secrets) as $secret) {
        if ($secret === '') {
            continue;
        }
        $value = str_replace([$secret, rawurlencode($secret)], '***', $value);
    }
    return trim(preg_replace('/[\r\n\x00-\x1F\x7F]+/', ' ', $value) ?? '');
}

function corsClassification(array $headers): string
{
    $value = trim((string) ($headers['access-control-allow-origin'] ?? ''));
    if ($value === '') {
        return 'absent';
    }
    return $value === '*' ? '*' : 'specific-origin';
}

function requestLimited(string $url): array
{
    $body = '';
    $headers = [];
    $currentHeaders = [];
    $truncated = false;
    $curl = curl_init($url);
    if ($curl === false) {
        return ['status' => 0, 'error' => 'init-fail', 'body' => '', 'headers' => [], 'content_type' => 'unknown'];
    }

    curl_setopt_array($curl, [
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 3,
        CURLOPT_CONNECTTIMEOUT => 7,
        CURLOPT_TIMEOUT => 12,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        CURLOPT_USERAGENT => 'Semyra-IPTV-Technical-Spike/10A.1',
        CURLOPT_RANGE => '0-' . (MAX_NETWORK_BYTES - 1),
        CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$headers, &$currentHeaders): int {
            $length = strlen($line);
            $trimmed = trim($line);
            if (preg_match('/^HTTP\//i', $trimmed) === 1) {
                $currentHeaders = [];
                return $length;
            }
            if ($trimmed === '') {
                if ($currentHeaders !== []) {
                    $headers = $currentHeaders;
                }
                return $length;
            }
            $separator = strpos($line, ':');
            if ($separator !== false) {
                $name = strtolower(trim(substr($line, 0, $separator)));
                $currentHeaders[$name] = trim(substr($line, $separator + 1));
            }
            return $length;
        },
        CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$body, &$truncated): int {
            $remaining = MAX_NETWORK_BYTES - strlen($body);
            if ($remaining <= 0) {
                $truncated = true;
                return 0;
            }
            if (strlen($chunk) > $remaining) {
                $body .= substr($chunk, 0, $remaining);
                $truncated = true;
                return 0;
            }
            $body .= $chunk;
            return strlen($chunk);
        },
    ]);

    curl_exec($curl);
    $errorNumber = curl_errno($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $contentType = (string) (curl_getinfo($curl, CURLINFO_CONTENT_TYPE) ?: 'unknown');
    $effectiveUrl = (string) curl_getinfo($curl, CURLINFO_EFFECTIVE_URL);
    $redirects = (int) curl_getinfo($curl, CURLINFO_REDIRECT_COUNT);
    $durationMs = (int) round(((float) curl_getinfo($curl, CURLINFO_TOTAL_TIME)) * 1000);
    $contentLength = (int) curl_getinfo($curl, CURLINFO_CONTENT_LENGTH_DOWNLOAD_T);
    curl_close($curl);

    $error = 'none';
    if ($errorNumber !== 0 && !($truncated && $errorNumber === CURLE_WRITE_ERROR)) {
        $tlsErrors = [35, 51, 58, 59, 60, 64, 66, 77, 80, 82, 83, 90, 91];
        $error = in_array($errorNumber, $tlsErrors, true) ? 'tls-fail' : 'network-fail';
    } elseif ($truncated) {
        $error = 'download-limited';
    }

    return [
        'status' => $status,
        'error' => $error,
        'body' => $body,
        'headers' => $headers,
        'content_type' => $contentType,
        'content_length' => $contentLength >= 0 ? $contentLength : null,
        'accept_ranges' => isset($headers['accept-ranges']) ? strtolower($headers['accept-ranges']) : 'unknown',
        'redirects' => $redirects,
        'duration_ms' => $durationMs,
        'final_protocol' => strtolower((string) parse_url($effectiveUrl, PHP_URL_SCHEME)) ?: 'unknown',
    ];
}

function detectPayload(string $body, string $contentType): string
{
    $prefix = ltrim(substr($body, 0, 1024), "\xEF\xBB\xBF\r\n\t ");
    if (str_starts_with($prefix, '#EXTM3U') || stripos($contentType, 'mpegurl') !== false) {
        return 'hls-manifest';
    }
    if (strlen($body) >= 12 && substr($body, 4, 4) === 'ftyp') {
        return 'mp4';
    }
    $length = strlen($body);
    for ($offset = 0; $offset < min(188, $length); ++$offset) {
        if ($length > $offset + 376 && ord($body[$offset]) === 0x47
            && ord($body[$offset + 188]) === 0x47
            && ord($body[$offset + 376]) === 0x47) {
            return 'mpeg-ts';
        }
    }
    return 'unknown';
}

function resolveRelativeUrl(string $baseUrl, string $relative): ?string
{
    $relative = trim($relative);
    if ($relative === '' || str_starts_with($relative, '#')) {
        return null;
    }
    if (preg_match('~^https?://~i', $relative) === 1) {
        return $relative;
    }
    $base = parse_url($baseUrl);
    if (!is_array($base) || !isset($base['scheme'], $base['host'])) {
        return null;
    }
    $authority = $base['scheme'] . '://' . $base['host'] . (isset($base['port']) ? ':' . $base['port'] : '');
    if (str_starts_with($relative, '//')) {
        return $base['scheme'] . ':' . $relative;
    }
    if (str_starts_with($relative, '/')) {
        return $authority . $relative;
    }
    $directory = isset($base['path']) ? preg_replace('~/[^/]*$~', '/', $base['path']) : '/';
    return $authority . $directory . $relative;
}

function analyzeManifest(string $body, string $baseUrl): array
{
    $lines = preg_split('/\r?\n/', $body) ?: [];
    $master = false;
    $media = false;
    $endList = false;
    $encryption = false;
    $keyMethod = 'none';
    $targetDuration = null;
    $resourceUrls = [];
    $segmentTypes = [];

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }
        if (str_starts_with($line, '#EXT-X-STREAM-INF')) {
            $master = true;
        } elseif (str_starts_with($line, '#EXTINF:')) {
            $media = true;
        } elseif ($line === '#EXT-X-ENDLIST') {
            $endList = true;
        } elseif (preg_match('/^#EXT-X-TARGETDURATION:(\d+)/', $line, $match) === 1) {
            $targetDuration = (int) $match[1];
        } elseif (preg_match('/^#EXT-X-KEY:.*METHOD=([^,]+)/', $line, $match) === 1) {
            $encryption = true;
            $keyMethod = strtoupper(trim($match[1], '"'));
        } elseif (!str_starts_with($line, '#')) {
            $resolved = resolveRelativeUrl($baseUrl, $line);
            if ($resolved !== null) {
                $resourceUrls[] = $resolved;
                $path = (string) parse_url($resolved, PHP_URL_PATH);
                $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
                $segmentTypes[$extension !== '' ? $extension : 'other'] = true;
            }
        }
    }

    return [
        'master' => $master,
        'media' => $media,
        'endlist' => $endList,
        'live_vod' => $media ? ($endList ? 'VOD' : 'LIVE') : 'unknown',
        'segment_type' => $segmentTypes === [] ? 'unknown' : implode(',', array_keys($segmentTypes)),
        'target_duration' => $targetDuration,
        'segment_count' => count($resourceUrls),
        'encryption' => $encryption,
        'key_method' => $keyMethod,
        'dvr' => $media && !$endList && count($resourceUrls) > 1 ? 'possible' : ($media ? 'none' : 'unknown'),
        'first_resource_url' => $resourceUrls[0] ?? null,
    ];
}

$stats = [
    'entries' => 0,
    'groups' => [],
    'protocols' => ['http' => 0, 'https' => 0, 'other' => 0],
    'extensions' => ['m3u8' => 0, 'ts' => 0, 'mp4' => 0, 'mkv' => 0, 'avi' => 0, 'm4v' => 0, 'webm' => 0, 'none' => 0, 'other' => 0],
    'structural' => ['live' => 0, 'movie' => 0, 'series' => 0],
    'credential_location' => ['path' => false, 'query' => false],
    'credential_patterns' => [],
    'credential_path_entries' => 0,
    'generic_path_patterns' => [],
    'generic_path_pattern_values' => [],
    'generic_first_segment_patterns' => [],
    'generic_first_segment_values' => [],
    'group_inference' => ['live' => 0, 'movie' => 0, 'series' => 0],
    'extinf_duration' => ['positive' => 0, 'zero' => 0, 'negative' => 0, 'absent' => 0],
    'hints' => ['H264' => 0, 'H265_HEVC' => 0, '4K' => 0, 'UHD' => 0, 'FHD' => 0, 'HD' => 0, 'SD' => 0],
    'explicit_nonstandard_port' => false,
    'host_hashes' => [],
    'live_extensions' => [],
];
$liveH264Pool = [];
$liveH265Pool = [];
$vodPool = [];
$firstNetworkUrl = null;
$pendingMetadata = '';
$pendingGroup = '';
$pendingExtinfDurationClass = 'absent';
$pendingExtinfDurationSeconds = null;
$reader = new SplFileObject($playlistPath, 'rb');
$reader->setFlags(SplFileObject::DROP_NEW_LINE);

foreach ($reader as $rawLine) {
    if (!is_string($rawLine)) {
        continue;
    }
    $line = trim($rawLine);
    if ($line === '') {
        continue;
    }
    if (str_starts_with($line, '#EXTINF:')) {
        $pendingMetadata = $line;
        $pendingGroup = preg_match('/group-title="([^"]*)"/i', $line, $groupMatch) === 1 ? $groupMatch[1] : 'Ungrouped';
        if (preg_match('/^#EXTINF:\s*([+-]?(?:\d+(?:\.\d*)?|\.\d+))/i', $line, $durationMatch) === 1) {
            $pendingExtinfDurationSeconds = (float) $durationMatch[1];
            $pendingExtinfDurationClass = match (true) {
                $pendingExtinfDurationSeconds > 0 => 'positive',
                $pendingExtinfDurationSeconds < 0 => 'negative',
                default => 'zero',
            };
        } else {
            $pendingExtinfDurationClass = 'absent';
            $pendingExtinfDurationSeconds = null;
        }
        continue;
    }
    if (str_starts_with($line, '#')) {
        continue;
    }

    $stats['entries']++;
    $stats['extinf_duration'][$pendingExtinfDurationClass]++;
    $stats['groups'][$pendingGroup] = ($stats['groups'][$pendingGroup] ?? 0) + 1;
    $parts = parse_url($line);
    if (!is_array($parts)) {
        $stats['protocols']['other']++;
        $stats['extensions']['none']++;
        $pendingMetadata = '';
        $pendingGroup = '';
        $pendingExtinfDurationClass = 'absent';
        $pendingExtinfDurationSeconds = null;
        continue;
    }

    $scheme = strtolower((string) ($parts['scheme'] ?? 'other'));
    $scheme = in_array($scheme, ['http', 'https'], true) ? $scheme : 'other';
    $stats['protocols'][$scheme]++;
    $path = (string) ($parts['path'] ?? '');
    $pathSegments = array_values(array_filter(explode('/', trim($path, '/')), static fn (string $part): bool => $part !== ''));
    if (count($pathSegments) >= 2) {
        $firstSegment = rawurldecode($pathSegments[0]);
        $firstSegmentHash = hash('sha256', $firstSegment);
        $stats['generic_first_segment_patterns'][$firstSegmentHash] = ($stats['generic_first_segment_patterns'][$firstSegmentHash] ?? 0) + 1;
        if (!isset($stats['generic_first_segment_values'][$firstSegmentHash])
            && count($stats['generic_first_segment_values']) < 100) {
            $stats['generic_first_segment_values'][$firstSegmentHash] = $firstSegment;
        }
    }
    if (count($pathSegments) >= 3) {
        $firstSegment = rawurldecode($pathSegments[0]);
        $secondSegment = rawurldecode($pathSegments[1]);
        $genericPatternHash = hash('sha256', $firstSegment . "\0" . $secondSegment);
        $stats['generic_path_patterns'][$genericPatternHash] = ($stats['generic_path_patterns'][$genericPatternHash] ?? 0) + 1;
        if (!isset($stats['generic_path_pattern_values'][$genericPatternHash])
            && count($stats['generic_path_pattern_values']) < 100) {
            $stats['generic_path_pattern_values'][$genericPatternHash] = [$firstSegment, $secondSegment];
        }
    }
    $extension = extensionBucket($path);
    $stats['extensions'][$extension]++;
    if (isset($parts['host'])) {
        $host = strtolower((string) $parts['host']);
        $stats['host_hashes'][hash('sha256', $host)] = true;
        if (count($rawHosts) < 100) {
            $rawHosts[$host] = true;
        }
    }
    if (isset($parts['port'])) {
        $port = (int) $parts['port'];
        if (($scheme === 'http' && $port !== 80) || ($scheme === 'https' && $port !== 443)) {
            $stats['explicit_nonstandard_port'] = true;
        }
    }

    $kind = null;
    if (preg_match('~/(live|movie|series)/([^/]+)/([^/]+)/([^/?#]+)~i', $path, $xtreamMatch) === 1) {
        $kind = strtolower($xtreamMatch[1]);
        $stats['structural'][$kind]++;
        $stats['credential_location']['path'] = true;
        $stats['credential_path_entries']++;
        registerSecret($secrets, rawurldecode($xtreamMatch[2]));
        registerSecret($secrets, rawurldecode($xtreamMatch[3]));
        $patternHash = hash('sha256', rawurldecode($xtreamMatch[2]) . "\0" . rawurldecode($xtreamMatch[3]));
        $stats['credential_patterns'][$patternHash] = ($stats['credential_patterns'][$patternHash] ?? 0) + 1;
    }
    if (isset($parts['query'])) {
        parse_str((string) $parts['query'], $query);
        foreach (SENSITIVE_QUERY_KEYS as $key) {
            if (isset($query[$key]) && is_scalar($query[$key]) && (string) $query[$key] !== '') {
                $stats['credential_location']['query'] = true;
                registerSecret($secrets, (string) $query[$key]);
            }
        }
    }

    $inferredKind = $kind ?? groupKind($pendingGroup);
    if ($kind === null && $inferredKind !== null) {
        $stats['group_inference'][$inferredKind]++;
    }

    $metadataForHints = $pendingMetadata . ' ' . $pendingGroup;
    foreach ([
        'H264' => '/\bH264\b/i',
        'H265_HEVC' => '/\b(?:H265|HEVC)\b/i',
        '4K' => '/\b4K\b/i',
        'UHD' => '/\bUHD\b/i',
        'FHD' => '/\bFHD\b/i',
        'HD' => '/\bHD\b/i',
        'SD' => '/\bSD\b/i',
    ] as $hint => $pattern) {
        if (preg_match($pattern, $metadataForHints) === 1) {
            $stats['hints'][$hint]++;
        }
    }

    if ($inferredKind === 'live') {
        $stats['live_extensions'][$extension] = ($stats['live_extensions'][$extension] ?? 0) + 1;
        $liveCandidate = [
            'url' => $line,
            'extension' => $extension,
            'score' => candidateScore($metadataForHints),
            'h265_hint' => preg_match('/\b(?:H265|HEVC)\b/i', $metadataForHints) === 1,
            'extinf_duration_class' => $pendingExtinfDurationClass,
            'extinf_duration_seconds' => $pendingExtinfDurationSeconds,
        ];
        if ($liveCandidate['h265_hint']) {
            offerCandidate($liveH265Pool, $liveCandidate);
        } else {
            offerCandidate($liveH264Pool, $liveCandidate);
        }
    } elseif ($inferredKind === 'movie' || $inferredKind === 'series') {
        offerCandidate($vodPool, [
            'url' => $line,
            'extension' => $extension,
            'score' => candidateScore($metadataForHints),
            'h265_hint' => preg_match('/\b(?:H265|HEVC)\b/i', $metadataForHints) === 1,
            'extinf_duration_class' => $pendingExtinfDurationClass,
            'extinf_duration_seconds' => $pendingExtinfDurationSeconds,
        ]);
    }
    if ($firstNetworkUrl === null && in_array($scheme, ['http', 'https'], true)) {
        $firstNetworkUrl = $line;
    }
    $pendingMetadata = '';
    $pendingGroup = '';
    $pendingExtinfDurationClass = 'absent';
    $pendingExtinfDurationSeconds = null;
}

arsort($stats['groups']);
arsort($stats['live_extensions']);
$genericCredentialPattern = null;
if ($stats['generic_path_patterns'] !== []) {
    arsort($stats['generic_path_patterns']);
    $genericPatternHash = (string) array_key_first($stats['generic_path_patterns']);
    $genericPatternCount = (int) $stats['generic_path_patterns'][$genericPatternHash];
    if ($stats['entries'] > 0 && $genericPatternCount / $stats['entries'] >= 0.90
        && isset($stats['generic_path_pattern_values'][$genericPatternHash])) {
        $genericCredentialPattern = $genericPatternHash;
        $stats['credential_location']['path'] = true;
        foreach ($stats['generic_path_pattern_values'][$genericPatternHash] as $credentialSegment) {
            registerSecret($secrets, (string) $credentialSegment);
        }
    }
}
if ($genericCredentialPattern === null && $stats['generic_first_segment_patterns'] !== []) {
    arsort($stats['generic_first_segment_patterns']);
    $firstSegmentHash = (string) array_key_first($stats['generic_first_segment_patterns']);
    $firstSegmentCount = (int) $stats['generic_first_segment_patterns'][$firstSegmentHash];
    $firstSegmentValue = $stats['generic_first_segment_values'][$firstSegmentHash] ?? null;
    if ($stats['entries'] > 0 && $firstSegmentCount / $stats['entries'] >= 0.90
        && is_string($firstSegmentValue) && looksLikeCredentialSegment($firstSegmentValue)) {
        $genericCredentialPattern = $firstSegmentHash;
        $stats['credential_location']['path'] = true;
        registerSecret($secrets, $firstSegmentValue);
    }
}
$commonLiveExtension = array_key_first($stats['live_extensions']);
$selectedLive = array_values(array_filter(
    $liveH264Pool,
    static fn (array $candidate): bool => $commonLiveExtension === null || $candidate['extension'] === $commonLiveExtension,
));
if ($selectedLive === []) {
    $selectedLive = $liveH264Pool;
}
$selectedLive = array_slice($selectedLive, 0, 6);
$selectedH265 = array_slice($liveH265Pool, 0, 3);
$mp4Vod = array_values(array_filter($vodPool, static fn (array $candidate): bool => $candidate['extension'] === 'mp4'));
$selectedVod = array_slice($mp4Vod !== [] ? $mp4Vod : $vodPool, 0, 5);

$networkResults = [];
$hlsDetails = null;
$segmentDetails = null;
$confirmedCandidates = [];
foreach ([
    ['kind' => 'LIVE_H264', 'items' => $selectedLive, 'limit' => 2, 'is_live' => true],
    ['kind' => 'LIVE_H265', 'items' => $selectedH265, 'limit' => 1, 'is_live' => true],
    ['kind' => 'VOD', 'items' => $selectedVod, 'limit' => 1, 'is_live' => false],
] as $selection) {
    $accepted = 0;
    foreach ($selection['items'] as $candidate) {
        $response = requestLimited($candidate['url']);
        $payload = detectPayload($response['body'], $response['content_type']);
        $cors = corsClassification($response['headers']);
        $isAccepted = $response['status'] === 200 && $payload === 'mpeg-ts' && $cors !== 'absent';
        if (!$isAccepted) {
            continue;
        }
        ++$accepted;
        $label = $selection['kind'] . '_' . $accepted;
        $networkResults[] = [
            'label' => $label,
            'declared' => $candidate['extension'],
            'status' => $response['status'],
            'redirects' => $response['redirects'],
            'content_type' => $response['content_type'],
            'content_length' => $response['content_length'],
            'accept_ranges' => $response['accept_ranges'],
            'protocol' => $response['final_protocol'],
            'duration_ms' => $response['duration_ms'],
            'payload' => $payload,
            'cors' => $cors,
            'result' => $response['error'],
        ];
        $confirmedCandidates[$label] = [
            'url' => $candidate['url'],
            'is_live' => $selection['is_live'],
            'h265_hint' => $candidate['h265_hint'],
            'payload' => $payload,
            'cors' => $cors,
            'extinf_duration_class' => $candidate['extinf_duration_class'],
            'extinf_duration_seconds' => $candidate['extinf_duration_seconds'],
        ];

        if ($payload === 'hls-manifest' && $hlsDetails === null) {
            $initialManifest = analyzeManifest($response['body'], $candidate['url']);
            $manifestForSegments = $initialManifest;
            $manifestCors = corsClassification($response['headers']);
            if ($initialManifest['master'] && is_string($initialManifest['first_resource_url'])) {
                $mediaResponse = requestLimited($initialManifest['first_resource_url']);
                if (detectPayload($mediaResponse['body'], $mediaResponse['content_type']) === 'hls-manifest') {
                    $manifestForSegments = analyzeManifest($mediaResponse['body'], $initialManifest['first_resource_url']);
                    $manifestCors = corsClassification($mediaResponse['headers']);
                }
            }
            $hlsDetails = array_merge($manifestForSegments, [
                'master' => $initialManifest['master'],
                'manifest_cors' => $manifestCors,
            ]);
            if (is_string($manifestForSegments['first_resource_url'])) {
                $segmentResponse = requestLimited($manifestForSegments['first_resource_url']);
                $segmentDetails = [
                    'status' => $segmentResponse['status'],
                    'cors' => corsClassification($segmentResponse['headers']),
                    'content_type' => $segmentResponse['content_type'],
                    'payload' => detectPayload($segmentResponse['body'], $segmentResponse['content_type']),
                ];
            }
        }
        if ($accepted >= $selection['limit']) {
            break;
        }
    }
}

$candidateMap = [
    'generated_at' => gmdate('c'),
    'candidates' => [],
];
foreach ($confirmedCandidates as $label => $candidate) {
    $candidateId = bin2hex(random_bytes(16));
    $candidateMap['candidates'][$candidateId] = [
        'label' => $label,
        'url' => $candidate['url'],
        'is_live' => $candidate['is_live'],
        'h265_hint' => $candidate['h265_hint'],
        'payload' => $candidate['payload'],
        'cors' => $candidate['cors'],
        'extinf_duration_class' => $candidate['extinf_duration_class'],
        'extinf_duration_seconds' => $candidate['extinf_duration_seconds'],
    ];
}
$encodedCandidateMap = json_encode($candidateMap, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
file_put_contents($candidateMapPath, $encodedCandidateMap . PHP_EOL, LOCK_EX);

$httpsProbe = ['result' => 'not-tested', 'tls' => 'not-tested'];
if ($stats['protocols']['http'] > 0 && $stats['protocols']['https'] === 0 && is_string($firstNetworkUrl)) {
    $probeUrl = preg_replace('/^http:/i', 'https:', $firstNetworkUrl, 1);
    if (is_string($probeUrl)) {
        $probe = requestLimited($probeUrl);
        $httpsProbe['result'] = $probe['status'] > 0 ? 'success' : 'fail';
        $httpsProbe['tls'] = $probe['error'] === 'tls-fail' ? 'fail' : ($probe['status'] > 0 ? 'success' : 'not-tested');
    }
}

$credentialLocation = match (true) {
    $stats['credential_location']['path'] && $stats['credential_location']['query'] => 'both',
    $stats['credential_location']['path'] => 'path',
    $stats['credential_location']['query'] => 'query',
    default => 'unknown',
};
$embeddedCredentials = $credentialLocation !== 'unknown';
$sharedCredentialPattern = ($stats['credential_path_entries'] > 0 && count($stats['credential_patterns']) === 1)
    || $genericCredentialPattern !== null;

$report = [];
$report[] = 'LOCAL IPTV PLAYLIST ANALYSIS';
$report[] = '';
$report[] = 'File: playlist_venlomxo1402_plus.m3u';
$report[] = 'File size: ' . filesize($playlistPath) . ' bytes';
$report[] = 'SHA-256: ' . hash_file('sha256', $playlistPath);
$report[] = 'Entries: ' . $stats['entries'];
$report[] = 'Total groups: ' . count($stats['groups']);
$report[] = 'Unique provider hosts: ' . count($stats['host_hashes']);
$report[] = 'Explicit non-standard port: ' . ($stats['explicit_nonstandard_port'] ? 'yes' : 'no');
$report[] = '';
$report[] = 'Protocols:';
$report[] = 'HTTP: ' . $stats['protocols']['http'];
$report[] = 'HTTPS: ' . $stats['protocols']['https'];
$report[] = 'Other: ' . $stats['protocols']['other'];
$report[] = '';
$report[] = 'EXTINF durations:';
$report[] = 'Positive: ' . $stats['extinf_duration']['positive'];
$report[] = 'Zero: ' . $stats['extinf_duration']['zero'];
$report[] = 'Negative: ' . $stats['extinf_duration']['negative'];
$report[] = 'Absent: ' . $stats['extinf_duration']['absent'];
$vodExtinf = $confirmedCandidates['VOD_1'] ?? null;
$report[] = 'VOD_1 EXTINF duration: ' . (is_array($vodExtinf) ? $vodExtinf['extinf_duration_class'] : 'unavailable');
if (is_array($vodExtinf) && $vodExtinf['extinf_duration_class'] === 'positive') {
    $report[] = 'VOD_1 EXTINF duration seconds: ' . (string) $vodExtinf['extinf_duration_seconds'];
}
$report[] = '';
$report[] = 'Xtream-style:';
$report[] = 'Live: ' . ($stats['structural']['live'] > 0 ? 'yes' : 'no') . ' (' . $stats['structural']['live'] . ')';
$report[] = 'Movies: ' . ($stats['structural']['movie'] > 0 ? 'yes' : 'no') . ' (' . $stats['structural']['movie'] . ')';
$report[] = 'Series: ' . ($stats['structural']['series'] > 0 ? 'yes' : 'no') . ' (' . $stats['structural']['series'] . ')';
$report[] = 'Group-structural Live candidates: ' . $stats['group_inference']['live'];
$report[] = 'Group-structural Movie candidates: ' . $stats['group_inference']['movie'];
$report[] = 'Group-structural Series candidates: ' . $stats['group_inference']['series'];
$report[] = '';
$report[] = 'Credential pattern:';
$report[] = 'Embedded: ' . ($embeddedCredentials ? 'yes' : 'no');
$report[] = 'Location: ' . $credentialLocation;
$report[] = 'Shared pattern: ' . ($sharedCredentialPattern ? 'yes' : 'no');
$report[] = '';
$report[] = 'URL extensions:';
foreach ($stats['extensions'] as $extension => $count) {
    $report[] = $extension . ': ' . $count;
}
$report[] = '';
$report[] = 'Top groups:';
$groupPosition = 0;
foreach ($stats['groups'] as $groupName => $count) {
    $safeName = safeGroupName((string) $groupName, $secrets);
    if ($safeName === null) {
        continue;
    }
    $report[] = ($groupPosition + 1) . '. ' . $safeName . ': ' . $count;
    if (++$groupPosition >= 20) {
        break;
    }
}
$report[] = '';
$report[] = 'Metadata hints (metadata hint; codec not verified):';
foreach ($stats['hints'] as $hint => $count) {
    $report[] = $hint . ': ' . $count;
}
$report[] = '';
$report[] = 'Network candidates:';
foreach ($networkResults as $result) {
    $report[] = '';
    $report[] = $result['label'];
    $report[] = 'Declared type: ' . $result['declared'];
    $report[] = 'HTTP status: ' . $result['status'];
    $report[] = 'Redirect count: ' . $result['redirects'];
    $report[] = 'Content-Type: ' . safeScalar((string) $result['content_type'], $secrets);
    $report[] = 'Content-Length: ' . ($result['content_length'] ?? 'unknown');
    $report[] = 'Accept-Ranges: ' . safeScalar((string) $result['accept_ranges'], $secrets);
    $report[] = 'Final protocol: ' . $result['protocol'];
    $report[] = 'Approximate time: ' . $result['duration_ms'] . ' ms';
    $report[] = 'Detected payload: ' . $result['payload'];
    $report[] = 'CORS: ' . $result['cors'];
    $report[] = 'Result: ' . $result['result'];
}
$report[] = '';
$report[] = 'HLS:';
$report[] = 'Manifest detected: ' . ($hlsDetails !== null ? 'yes' : 'no');
if ($hlsDetails !== null) {
    $report[] = 'Master playlist: ' . ($hlsDetails['master'] ? 'yes' : 'no');
    $report[] = 'Media playlist: ' . ($hlsDetails['media'] ? 'yes' : 'no');
    $report[] = 'Live/VOD: ' . $hlsDetails['live_vod'];
    $report[] = 'ENDLIST present: ' . ($hlsDetails['endlist'] ? 'yes' : 'no');
    $report[] = 'Segment type: ' . $hlsDetails['segment_type'];
    $report[] = 'Target duration: ' . ($hlsDetails['target_duration'] ?? 'unknown');
    $report[] = 'Visible segment count: ' . $hlsDetails['segment_count'];
    $report[] = 'Encryption tag present: ' . ($hlsDetails['encryption'] ? 'yes' : 'no');
    $report[] = 'Key method: ' . $hlsDetails['key_method'];
    $report[] = 'DVR indication: ' . $hlsDetails['dvr'];
    $report[] = 'Manifest CORS: ' . $hlsDetails['manifest_cors'];
}
if ($segmentDetails !== null) {
    $report[] = 'Segment HTTP status: ' . $segmentDetails['status'];
    $report[] = 'Segment CORS: ' . $segmentDetails['cors'];
    $report[] = 'Segment Content-Type: ' . safeScalar((string) $segmentDetails['content_type'], $secrets);
    $report[] = 'Segment payload: ' . $segmentDetails['payload'];
}
$report[] = '';
$report[] = 'Provider HTTPS probe: ' . $httpsProbe['result'];
$report[] = 'TLS: ' . $httpsProbe['tls'];
$report[] = '';
$hasHls = $hlsDetails !== null;
$hasTsLive = count(array_filter(
    $networkResults,
    static fn (array $result): bool => str_starts_with($result['label'], 'LIVE_') && $result['payload'] === 'mpeg-ts',
)) > 0;
$hasMp4Vod = count(array_filter(
    $networkResults,
    static fn (array $result): bool => str_starts_with($result['label'], 'VOD_') && $result['payload'] === 'mp4',
)) > 0;
$report[] = 'BROWSER IMPLICATION:';
$report[] = 'HLS.js path: ' . ($hasHls ? 'possible' : ($hasTsLive ? 'unlikely' : 'unknown'));
$report[] = 'Direct MPEG-TS in Chrome: ' . ($hasTsLive ? 'likely unsupported' : 'not applicable');
$report[] = 'MP4 VOD: ' . ($hasMp4Vod ? 'possible' : 'unknown');
$report[] = 'Production HTTPS risk: ' . ($stats['protocols']['http'] > 0 ? 'risk' : ($stats['protocols']['https'] > 0 ? 'no-obvious-risk' : 'unknown'));
$report[] = '';
$report[] = 'No credentials, hosts, stream IDs, query strings, or raw URLs are included.';

$sanitizedReport = safeScalar(implode(PHP_EOL, $report), $secrets);
// Restore deliberate line breaks after field-level sanitization.
$sanitizedReport = implode(PHP_EOL, array_map(
    static fn (string $line): string => safeScalar($line, $secrets),
    $report,
)) . PHP_EOL;
file_put_contents($reportPath, $sanitizedReport, LOCK_EX);

$gitDiff = shell_exec('git -C ' . escapeshellarg($root) . ' diff --no-ext-diff 2>NUL') ?: '';
$leakTargets = $sanitizedReport . $gitDiff;
foreach ([
    __DIR__ . '/README.md',
    __DIR__ . '/analyze-local-playlist.php',
    __DIR__ . '/candidate.php',
    __DIR__ . '/index.html',
    __DIR__ . '/iptv-spike.css',
    __DIR__ . '/iptv-spike.js',
    __DIR__ . '/iptv-spike.test.cjs',
    __DIR__ . '/local-playlist-report.txt',
    __DIR__ . '/mpegts-playback-report.txt',
    __DIR__ . '/mpv-vod-parity-report.txt',
    __DIR__ . '/probe-mpv-vod.ps1',
    __DIR__ . '/mpegts-spike.js',
    __DIR__ . '/mpegts-spike.test.cjs',
    $root . '/docs/IPTV_TECHNICAL_SPIKE.md',
] as $leakTargetPath) {
    if (is_file($leakTargetPath)) {
        $leakTargets .= (string) file_get_contents($leakTargetPath);
    }
}
$leakDetected = preg_match('~https?://\S+~i', $sanitizedReport) === 1;
foreach (array_keys($secrets) as $secret) {
    if ($secret !== '' && (str_contains($leakTargets, $secret) || str_contains($leakTargets, rawurlencode($secret)))) {
        $leakDetected = true;
        break;
    }
}
if (!$leakDetected) {
    foreach (array_keys($rawHosts) as $host) {
        if ($host !== '' && str_contains($leakTargets, $host)) {
            $leakDetected = true;
            break;
        }
    }
}
if (!$leakDetected) {
    $verificationReader = new SplFileObject($playlistPath, 'rb');
    $verificationReader->setFlags(SplFileObject::DROP_NEW_LINE);
    foreach ($verificationReader as $rawLine) {
        if (!is_string($rawLine)) {
            continue;
        }
        $url = trim($rawLine);
        if (preg_match('~^https?://~i', $url) === 1 && str_contains($leakTargets, $url)) {
            $leakDetected = true;
            break;
        }
    }
}

if ($leakDetected) {
    file_put_contents($reportPath, "LOCAL IPTV PLAYLIST ANALYSIS\n\nLeak check failed; report content removed.\n", LOCK_EX);
    fwrite(STDERR, "Leak check: FAILED (report content removed)\n");
    exit(2);
}

fwrite(STDOUT, "Analysis: COMPLETE\n");
fwrite(STDOUT, "Report: tests/manual/iptv-spike/local-playlist-report.txt\n");
fwrite(STDOUT, "Leak check: PASSED\n");
