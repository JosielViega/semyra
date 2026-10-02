<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli' || !in_array('--dry-run', $argv, true)) {
    fwrite(STDERR, "Usage: php bridge-worker/worker.php --dry-run\n");
    exit(1);
}

$environment = trim((string) (getenv('APP_ENV') ?: 'production'));
$controlUrl = rtrim(trim((string) getenv('SEMYRA_CONTROL_URL')), '/');
$workerId = trim((string) getenv('SEMYRA_WORKER_ID'));
$workerSecret = trim((string) getenv('SEMYRA_WORKER_SECRET'));

if (preg_match('/^wrk_[a-f0-9]{32}$/', $workerId) !== 1
    || preg_match('/^[a-f0-9]{64}$/', $workerSecret) !== 1) {
    fwrite(STDERR, "Worker configuration is invalid.\n");
    exit(1);
}
$parts = parse_url($controlUrl);
$localHttp = in_array($environment, ['local', 'testing'], true)
    && ($parts['scheme'] ?? '') === 'http'
    && in_array(strtolower((string) ($parts['host'] ?? '')), ['127.0.0.1', 'localhost'], true);
if (($parts['scheme'] ?? '') !== 'https' && !$localHttp) {
    fwrite(STDERR, "Control URL must use HTTPS.\n");
    exit(1);
}

/** @return array{status: int, body: array<string, mixed>} */
function bridgeRequest(string $baseUrl, string $secret, string $path, array $fields): array
{
    $handle = curl_init($baseUrl . $path);
    if ($handle === false) {
        throw new RuntimeException('Control request initialization failed.');
    }
    curl_setopt_array($handle, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($fields, '', '&', PHP_QUERY_RFC3986),
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/x-www-form-urlencoded',
            'X-Semyra-Worker-Token: ' . $secret,
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_CONNECTTIMEOUT => 5,
    ]);
    $raw = curl_exec($handle);
    $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    $error = curl_error($handle);
    curl_close($handle);
    if (!is_string($raw) || $error !== '') {
        throw new RuntimeException('Control request failed.');
    }
    $body = $raw === '' ? [] : json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
    if (!is_array($body)) {
        throw new RuntimeException('Control response is invalid.');
    }

    return ['status' => $status, 'body' => $body];
}

try {
    $claim = bridgeRequest($controlUrl, $workerSecret, '/internal/media-bridge/claim', ['worker_id' => $workerId]);
    if ($claim['status'] === 204) {
        echo "bridge claim: no job\n";
        exit(0);
    }
    if ($claim['status'] !== 201) {
        throw new RuntimeException('Claim was rejected.');
    }
    $job = $claim['body'];
    foreach (['job_id', 'transmission_instance_id', 'lease_token', 'whip_endpoint'] as $field) {
        if (!isset($job[$field]) || $job[$field] === '') {
            throw new RuntimeException('Claim response is incomplete.');
        }
    }
    echo "bridge claim: accepted\n";
    echo "whip credentials received: yes\n";

    $common = [
        'worker_id' => $workerId,
        'job_id' => (string) $job['job_id'],
        'transmission_instance_id' => (string) $job['transmission_instance_id'],
        'lease_token' => (string) $job['lease_token'],
    ];
    $starting = bridgeRequest($controlUrl, $workerSecret, '/internal/media-bridge/heartbeat', $common + ['status' => 'starting']);
    if ($starting['status'] !== 200 || ($starting['body']['action'] ?? null) !== 'keep') {
        throw new RuntimeException('Starting heartbeat was rejected.');
    }
    echo "bridge heartbeat starting: keep\n";
    $running = bridgeRequest($controlUrl, $workerSecret, '/internal/media-bridge/heartbeat', $common + ['status' => 'running']);
    if ($running['status'] !== 200 || ($running['body']['action'] ?? null) !== 'keep') {
        throw new RuntimeException('Running heartbeat was rejected.');
    }
    echo "bridge heartbeat running: keep\n";
    $stopped = bridgeRequest($controlUrl, $workerSecret, '/internal/media-bridge/report', $common + ['status' => 'stopped']);
    if ($stopped['status'] !== 200) {
        throw new RuntimeException('Stopped report was rejected.');
    }
    echo "bridge report stopped: accepted\n";
} catch (Throwable) {
    fwrite(STDERR, "Bridge dry-run failed.\n");
    exit(1);
}
