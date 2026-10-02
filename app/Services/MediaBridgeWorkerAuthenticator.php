<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Request;

final class MediaBridgeWorkerAuthenticator
{
    public function __construct(
        private readonly array $config,
        private readonly string $environment,
    ) {
    }

    public function accepts(Request $request): bool
    {
        if (($this->config['enabled'] ?? false) !== true || !$this->transportAllowed($request)) {
            return false;
        }
        $expected = (string) ($this->config['worker_secret'] ?? '');
        $provided = trim((string) $request->header('X-Semyra-Worker-Token', ''));
        if (preg_match('/^[a-f0-9]{64}$/', $expected) !== 1
            || preg_match('/^[a-f0-9]{64}$/', $provided) !== 1) {
            return false;
        }

        return hash_equals($expected, $provided);
    }

    private function transportAllowed(Request $request): bool
    {
        if ($request->isSecure()) {
            return true;
        }
        if (!in_array($this->environment, ['local', 'testing'], true)) {
            return false;
        }
        $host = strtolower(trim((string) $request->header('Host', '')));
        $host = preg_replace('/:\d+$/', '', $host);

        return in_array($host, ['localhost', '127.0.0.1'], true);
    }
}
