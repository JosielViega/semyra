<?php

declare(strict_types=1);

namespace Semyra\BridgeWorker;

use JsonException;

final class SourceCatalog
{
    /** @var array<string, string> */
    private array $sources;

    public function __construct(string $path)
    {
        try {
            $decoded = json_decode((string) @file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new WorkerException('catalog_invalid');
        }
        if (!is_array($decoded) || ($decoded['version'] ?? null) !== 1 || !is_array($decoded['sources'] ?? null)) {
            throw new WorkerException('catalog_invalid');
        }
        $this->sources = [];
        foreach ($decoded['sources'] as $reference => $source) {
            if (!is_string($reference) || preg_match('/^[A-Za-z0-9._:-]{1,96}$/', $reference) !== 1
                || !is_array($source) || ($source['type'] ?? null) !== 'http_mpegts'
                || !is_string($source['url'] ?? null) || !$this->allowedUrl($source['url'])) {
                throw new WorkerException('catalog_invalid');
            }
            $this->sources[$reference] = $source['url'];
        }
    }

    public function resolve(string $sourceReference): string
    {
        if (!array_key_exists($sourceReference, $this->sources)) {
            throw new WorkerException('source_not_found');
        }

        return $this->sources[$sourceReference];
    }

    private function allowedUrl(string $url): bool
    {
        $parts = parse_url($url);
        return is_array($parts)
            && in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)
            && trim((string) ($parts['host'] ?? '')) !== '';
    }
}
