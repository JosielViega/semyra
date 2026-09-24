<?php

declare(strict_types=1);

namespace TemplateTools;

use RuntimeException;

final class PortRegistry
{
    private const VERSION = 1;

    public function __construct(private readonly string $filePath)
    {
        if (trim($this->filePath) === '') {
            throw new \InvalidArgumentException('The port registry path cannot be empty.');
        }
    }

    public static function forCurrentUser(): self
    {
        $override = getenv('MODELOPHP_PORT_REGISTRY');
        if (is_string($override) && trim($override) !== '') {
            return new self($override);
        }

        $profile = PHP_OS_FAMILY === 'Windows'
            ? self::firstEnvironmentValue(['USERPROFILE', 'HOME'])
            : self::firstEnvironmentValue(['HOME', 'USERPROFILE']);

        if ($profile === null && PHP_OS_FAMILY === 'Windows') {
            $drive = getenv('HOMEDRIVE');
            $path = getenv('HOMEPATH');
            if (is_string($drive) && $drive !== '' && is_string($path) && $path !== '') {
                $profile = $drive . $path;
            }
        }

        if ($profile === null || !is_dir($profile)) {
            throw new RuntimeException('Unable to locate the operating system user profile for the port registry.');
        }

        return new self(rtrim($profile, '/\\') . DIRECTORY_SEPARATOR . '.modeloPHP' . DIRECTORY_SEPARATOR . 'ports.json');
    }

    public function path(): string
    {
        return $this->filePath;
    }

    public function normalizeProjectPath(string $projectPath): string
    {
        $resolved = realpath($projectPath);
        $path = str_replace('\\', '/', $resolved !== false ? $resolved : $projectPath);
        $path = trim($path);
        $unc = str_starts_with($path, '//');
        $collapsed = preg_replace('#/+#', '/', ltrim($path, '/')) ?? ltrim($path, '/');
        $path = ($unc ? '//' : (str_starts_with($path, '/') ? '/' : '')) . $collapsed;

        $drive = '';
        if (preg_match('/^[A-Za-z]:/', $path, $matches) === 1) {
            $drive = strtoupper($matches[0]);
            $path = substr($path, 2);
        }

        $absolute = str_starts_with($path, '/');
        $segments = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                if ($segments !== [] && end($segments) !== '..') {
                    array_pop($segments);
                } elseif (!$absolute) {
                    $segments[] = '..';
                }
                continue;
            }
            $segments[] = $segment;
        }

        $prefix = $unc ? '//' : (($absolute || $drive !== '') ? '/' : '');
        $normalized = ($drive !== '' ? $drive : '') . $prefix . implode('/', $segments);
        if ($normalized === '') {
            $normalized = $absolute ? '/' : '.';
        }

        return PHP_OS_FAMILY === 'Windows' ? strtolower($normalized) : $normalized;
    }

    public function reservationFor(string $projectPath): ?int
    {
        if (!is_file($this->filePath)) {
            return null;
        }

        $project = $this->normalizeProjectPath($projectPath);

        return $this->withLock(false, static function (array &$data) use ($project): ?int {
            $port = $data['projects'][$project]['port'] ?? null;
            return is_int($port) ? $port : null;
        });
    }

    /** @return array{port: int, previousPort: ?int, created: bool, removedStale: int} */
    public function assign(
        string $projectPath,
        ?int $preferredPort,
        callable $systemAvailability,
    ): array {
        $project = $this->normalizeProjectPath($projectPath);

        return $this->withLock(true, function (array &$data) use ($project, $preferredPort, $systemAvailability): array {
            $removedStale = $this->removeClearlyMissingProjects($data, $project);
            $previousPort = $data['projects'][$project]['port'] ?? null;
            $created = !is_int($previousPort);

            if (is_int($previousPort)
                && $this->isManagedPort($previousPort)
                && $systemAvailability($previousPort)
            ) {
                $port = $previousPort;
            } elseif ($preferredPort !== null
                && $this->isManagedPort($preferredPort)
                && !$this->isReservedByAnotherProject($data, $project, $preferredPort)
                && $systemAvailability($preferredPort)
            ) {
                $port = $preferredPort;
            } else {
                $port = Port::findAvailable(
                    Port::DEFAULT_START,
                    Port::DEFAULT_END,
                    fn (int $candidate): bool => !$this->isReservedByAnotherProject($data, $project, $candidate)
                        && $systemAvailability($candidate),
                );
            }

            $data['projects'][$project] = ['port' => $port];

            return [
                'port' => $port,
                'previousPort' => is_int($previousPort) ? $previousPort : null,
                'created' => $created,
                'removedStale' => $removedStale,
            ];
        });
    }

    public function release(string $projectPath): ?int
    {
        $project = $this->normalizeProjectPath($projectPath);

        if (!is_file($this->filePath)) {
            return null;
        }

        return $this->withLock(true, static function (array &$data) use ($project): ?int {
            $port = $data['projects'][$project]['port'] ?? null;
            unset($data['projects'][$project]);

            return is_int($port) ? $port : null;
        });
    }

    private function withLock(bool $write, callable $callback): mixed
    {
        $directory = dirname($this->filePath);
        if ($write && !is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create the port registry directory.');
        }

        $existed = is_file($this->filePath);
        $handle = fopen($this->filePath, $write ? 'c+' : 'r');
        if ($handle === false) {
            throw new RuntimeException('Unable to open the port registry.');
        }

        try {
            if (!flock($handle, $write ? LOCK_EX : LOCK_SH)) {
                throw new RuntimeException('Unable to lock the port registry.');
            }

            rewind($handle);
            $contents = stream_get_contents($handle);
            if ($contents === false) {
                throw new RuntimeException('Unable to read the port registry.');
            }

            $data = $this->decode($contents, $existed);
            $result = $callback($data);

            if ($write) {
                $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
                rewind($handle);
                if (!ftruncate($handle, 0)
                    || fwrite($handle, $json . PHP_EOL) === false
                    || !fflush($handle)
                ) {
                    throw new RuntimeException('Unable to save the port registry.');
                }

                if (PHP_OS_FAMILY !== 'Windows') {
                    chmod($this->filePath, 0600);
                }
            }

            flock($handle, LOCK_UN);
            return $result;
        } finally {
            fclose($handle);
        }
    }

    private function decode(string $contents, bool $existed): array
    {
        if (trim($contents) === '') {
            if ($existed) {
                throw new RuntimeException('The port registry is empty or corrupted. Preserve it and repair or remove it manually.');
            }

            return ['version' => self::VERSION, 'projects' => []];
        }

        try {
            $data = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new RuntimeException(
                'The port registry contains invalid JSON. It was preserved; repair or remove it manually.',
                0,
                $exception,
            );
        }

        if (!is_array($data)
            || ($data['version'] ?? null) !== self::VERSION
            || !isset($data['projects'])
            || !is_array($data['projects'])
        ) {
            throw new RuntimeException('The port registry format is invalid. It was preserved; repair or remove it manually.');
        }

        $projects = [];
        $portOwners = [];
        foreach ($data['projects'] as $path => $reservation) {
            $port = is_array($reservation) ? ($reservation['port'] ?? null) : null;
            if (!is_string($path) || !is_int($port) || !$this->isManagedPort($port)) {
                throw new RuntimeException('The port registry contains an invalid reservation. It was preserved.');
            }

            $normalized = $this->normalizeProjectPath($path);
            if (isset($projects[$normalized]) && $projects[$normalized]['port'] !== $port) {
                throw new RuntimeException('The port registry contains duplicate project paths. It was preserved.');
            }
            if (isset($portOwners[$port]) && $portOwners[$port] !== $normalized) {
                throw new RuntimeException('The port registry assigns one port to multiple projects. It was preserved.');
            }
            $projects[$normalized] = ['port' => $port];
            $portOwners[$port] = $normalized;
        }

        return ['version' => self::VERSION, 'projects' => $projects];
    }

    private function removeClearlyMissingProjects(array &$data, string $currentProject): int
    {
        $removed = 0;
        foreach (array_keys($data['projects']) as $project) {
            if ($project === $currentProject || !$this->isClearlyMissing($project)) {
                continue;
            }

            unset($data['projects'][$project]);
            ++$removed;
        }

        return $removed;
    }

    private function isClearlyMissing(string $project): bool
    {
        clearstatcache(true, $project);
        if (file_exists($project) || is_dir($project)) {
            return false;
        }

        $parent = dirname($project);
        return $parent !== $project && is_dir($parent) && is_readable($parent);
    }

    private function isReservedByAnotherProject(array $data, string $project, int $port): bool
    {
        foreach ($data['projects'] as $path => $reservation) {
            if ($path !== $project && $reservation['port'] === $port) {
                return true;
            }
        }

        return false;
    }

    private function isManagedPort(int $port): bool
    {
        return $port >= Port::DEFAULT_START && $port <= Port::DEFAULT_END;
    }

    private static function firstEnvironmentValue(array $keys): ?string
    {
        foreach ($keys as $key) {
            $value = getenv($key);
            if (is_string($value) && trim($value) !== '') {
                return $value;
            }
        }

        return null;
    }
}
