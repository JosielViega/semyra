<?php

declare(strict_types=1);

namespace TemplateTools;

final class ProjectSetup
{
    /** @var callable(int): bool */
    private $availabilityCheck;

    public function __construct(
        private readonly string $root,
        ?callable $availabilityCheck = null,
        private readonly ?PortRegistry $registry = null,
    ) {
        $this->availabilityCheck = $availabilityCheck ?? Port::isAvailable(...);
    }

    /** @return array{created: bool, changed: bool, port: int, urlChanged: bool, reservationCreated: bool, previousPort: ?int, removedStale: int, registryPath: string} */
    public function run(): array
    {
        $example = $this->root . DIRECTORY_SEPARATOR . '.env.example';
        $environment = $this->root . DIRECTORY_SEPARATOR . '.env';

        if (!is_file($example)) {
            throw new \RuntimeException('.env.example was not found.');
        }

        $created = !is_file($environment);
        $contents = file_get_contents($created ? $example : $environment);
        if ($contents === false) {
            throw new \RuntimeException('Unable to read .env.');
        }

        $configuredPort = $this->readValue($contents, 'APP_PORT');
        $preferredPort = Port::isValid($configuredPort) ? (int) $configuredPort : null;
        $registry = $this->registry ?? PortRegistry::forCurrentUser();
        $reservation = $registry->assign($this->root, $preferredPort, $this->availabilityCheck);
        $port = $reservation['port'];
        $changed = false;

        if ($preferredPort !== $port) {
            $contents = $this->writeValue($contents, 'APP_PORT', (string) $port);
            $changed = true;
        }

        $url = $this->readValue($contents, 'APP_URL');
        $urlChanged = false;
        if ($created || $url === null || $url === '' || $this->isLocalUrl($url)) {
            $updatedUrl = $this->localUrlWithPort($url, $port);
            if ($updatedUrl !== $url) {
                $contents = $this->writeValue($contents, 'APP_URL', $updatedUrl);
                $changed = true;
                $urlChanged = true;
            }
        }

        if (($created || $changed) && file_put_contents($environment, $contents, LOCK_EX) === false) {
            throw new \RuntimeException('Unable to update .env.');
        }

        return [
            'created' => $created,
            'changed' => $changed,
            'port' => $port,
            'urlChanged' => $urlChanged,
            'reservationCreated' => $reservation['created'],
            'previousPort' => $reservation['previousPort'],
            'removedStale' => $reservation['removedStale'],
            'registryPath' => $registry->path(),
        ];
    }

    private function readValue(string $contents, string $key): ?string
    {
        if (preg_match('/^' . preg_quote($key, '/') . '=(.*)$/m', $contents, $matches) !== 1) {
            return null;
        }

        return trim(trim($matches[1]), "\"'");
    }

    private function writeValue(string $contents, string $key, string $value): string
    {
        $line = $key . '=' . $value;
        $pattern = '/^' . preg_quote($key, '/') . '=.*$/m';

        if (preg_match($pattern, $contents) === 1) {
            return (string) preg_replace($pattern, $line, $contents, 1);
        }

        $newline = str_contains($contents, "\r\n") ? "\r\n" : "\n";
        return rtrim($contents, "\r\n") . $newline . $line . $newline;
    }

    private function isLocalUrl(string $url): bool
    {
        $host = parse_url($url, PHP_URL_HOST);

        return in_array($host, ['localhost', '127.0.0.1', '::1'], true);
    }

    private function localUrlWithPort(?string $url, int $port): string
    {
        if ($url === null || $url === '' || !$this->isLocalUrl($url)) {
            return "http://localhost:{$port}";
        }

        $scheme = (string) (parse_url($url, PHP_URL_SCHEME) ?: 'http');
        $host = (string) parse_url($url, PHP_URL_HOST);
        $path = rtrim((string) parse_url($url, PHP_URL_PATH), '/');

        return "{$scheme}://{$host}:{$port}{$path}";
    }
}
