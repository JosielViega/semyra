<?php

declare(strict_types=1);

namespace Semyra\BridgeWorker;

interface ControlPlaneClient
{
    public function claim(string $workerId): ?array;
    public function heartbeat(array $job, string $status): array;
    public function report(array $job, string $status, ?string $errorCode = null): void;
}

final class HttpControlPlaneClient implements ControlPlaneClient
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $secret,
    ) {
    }

    public function claim(string $workerId): ?array
    {
        $response = $this->request('/internal/media-bridge/claim', ['worker_id' => $workerId], 8);
        if ($response['status'] === 204) {
            return null;
        }
        if ($response['status'] !== 201) {
            throw new ControlException($response['status'] >= 500 ? 'unavailable' : 'rejected');
        }
        $job = $response['body'];
        foreach (['job_id', 'transmission_instance_id', 'source_ref', 'lease_token', 'lease_seconds', 'whip_endpoint'] as $field) {
            if (!isset($job[$field]) || $job[$field] === '') {
                throw new ControlException('invalid_response');
            }
        }

        return $job;
    }

    public function heartbeat(array $job, string $status): array
    {
        $response = $this->request('/internal/media-bridge/heartbeat', $this->fields($job) + ['status' => $status], 4);
        if ($response['status'] === 409 && ($response['body']['error'] ?? null) === 'lease_lost') {
            throw new ControlException('lease_lost');
        }
        if ($response['status'] >= 500 || $response['status'] === 0) {
            throw new ControlException('unavailable');
        }
        if ($response['status'] !== 200 || !in_array($response['body']['action'] ?? null, ['keep', 'stop'], true)) {
            throw new ControlException('rejected');
        }

        return $response['body'];
    }

    public function report(array $job, string $status, ?string $errorCode = null): void
    {
        $fields = $this->fields($job) + ['status' => $status];
        if ($errorCode !== null) {
            $fields['error_code'] = $errorCode;
        }
        $response = $this->request('/internal/media-bridge/report', $fields, 4);
        if ($response['status'] === 409 && ($response['body']['error'] ?? null) === 'lease_lost') {
            throw new ControlException('lease_lost');
        }
        if ($response['status'] >= 500 || $response['status'] === 0) {
            throw new ControlException('unavailable');
        }
        if ($response['status'] !== 200) {
            throw new ControlException('rejected');
        }
    }

    /** @return array<string, string> */
    private function fields(array $job): array
    {
        return [
            'worker_id' => (string) $job['worker_id'],
            'job_id' => (string) $job['job_id'],
            'transmission_instance_id' => (string) $job['transmission_instance_id'],
            'lease_token' => (string) $job['lease_token'],
        ];
    }

    /** @return array{status: int, body: array<string, mixed>} */
    private function request(string $path, array $fields, int $timeout): array
    {
        $handle = curl_init($this->baseUrl . $path);
        if ($handle === false) {
            throw new ControlException('unavailable');
        }
        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($fields, '', '&', PHP_QUERY_RFC3986),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/x-www-form-urlencoded',
                'X-Semyra-Worker-Token: ' . $this->secret,
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        ]);
        $raw = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $failed = !is_string($raw) || curl_errno($handle) !== CURLE_OK;
        curl_close($handle);
        if ($failed) {
            throw new ControlException('unavailable');
        }
        try {
            $body = $raw === '' ? [] : json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new ControlException('invalid_response');
        }
        if (!is_array($body)) {
            throw new ControlException('invalid_response');
        }

        return ['status' => $status, 'body' => $body];
    }
}
