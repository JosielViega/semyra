<?php

declare(strict_types=1);

namespace TemplateTools;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

final class BridgeWorkerReleaseBuilder
{
    private const ROOT_FILES = [
        'worker.php',
        'bootstrap.php',
        'feeder.php',
        'media-helper.php',
        'media-watchdog.php',
        'README.md',
    ];

    private const OPS_FILES = [
        'deploy/bridge-worker/README.md' => 'ops/README.md',
        'deploy/bridge-worker/systemd/semyra-bridge.service' => 'ops/systemd/semyra-bridge.service',
        'deploy/bridge-worker/systemd/bridge-worker.env.example' => 'ops/systemd/bridge-worker.env.example',
    ];

    public function __construct(
        private readonly string $root,
        private readonly string $output,
        private readonly ?string $sourceSha = null,
        private readonly ?bool $dirty = null,
        private readonly ?string $builtAt = null,
    ) {
    }

    /** @return array{files:int,source_sha:string,dirty:bool} */
    public function build(): array
    {
        $this->resetOutput();

        foreach (self::ROOT_FILES as $file) {
            $this->copy('bridge-worker/' . $file, $file);
        }

        $sources = glob($this->root . '/bridge-worker/src/*.php') ?: [];
        sort($sources, SORT_STRING);
        if ($sources === []) {
            throw new RuntimeException('Bridge worker sources are missing.');
        }
        foreach ($sources as $source) {
            $this->copy('bridge-worker/src/' . basename($source), 'src/' . basename($source));
        }

        foreach (self::OPS_FILES as $source => $target) {
            $this->copy($source, $target);
        }

        $sourceSha = $this->sourceSha ?? $this->gitValue('rev-parse HEAD', 'unknown');
        $dirty = $this->dirty ?? $this->detectDirty();
        $metadata = [
            'source_sha' => $sourceSha,
            'working_tree_dirty' => $dirty,
            'built_at_utc' => $this->builtAt ?? gmdate('Y-m-d\TH:i:s\Z'),
            'publishable' => !$dirty && preg_match('/^[a-f0-9]{40}$/', $sourceSha) === 1,
        ];
        $encoded = json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        file_put_contents($this->output . '/build-info.json', $encoded . PHP_EOL, LOCK_EX);

        $this->validate();

        return ['files' => $this->countFiles(), 'source_sha' => $sourceSha, 'dirty' => $dirty];
    }

    public function validate(): void
    {
        $forbiddenSegments = ['/.private/', '/runtime/', '/tests/', '/.git/', '/logs/', '/cache/'];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->output, FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterator as $item) {
            if (!$item->isFile()) {
                continue;
            }
            $relative = '/' . str_replace('\\', '/', substr($item->getPathname(), strlen($this->output) + 1));
            foreach ($forbiddenSegments as $segment) {
                if (str_contains($relative . '/', $segment)) {
                    throw new RuntimeException('Forbidden bridge release path: ' . $relative);
                }
            }
            if (in_array(strtolower($item->getFilename()), ['.env', 'bridge-sources.json', 'source-catalog.json'], true)) {
                throw new RuntimeException('Private bridge release file: ' . $relative);
            }
            $content = (string) file_get_contents($item->getPathname());
            if (preg_match('/\beyJ[A-Za-z0-9_-]{20,}\.[A-Za-z0-9_-]{20,}\.[A-Za-z0-9_-]{20,}\b/', $content) === 1
                || preg_match('~https?://[^\s/:@]+:[^\s/@]+@~i', $content) === 1) {
                throw new RuntimeException('Sensitive content detected in bridge release: ' . $relative);
            }
        }
    }

    private function copy(string $relativeSource, string $relativeTarget): void
    {
        $source = $this->root . '/' . $relativeSource;
        if (!is_file($source)) {
            throw new RuntimeException('Required bridge release file is missing: ' . $relativeSource);
        }
        $target = $this->output . '/' . $relativeTarget;
        $directory = dirname($target);
        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create bridge release directory.');
        }
        if (!copy($source, $target)) {
            throw new RuntimeException('Unable to copy bridge release file: ' . $relativeSource);
        }
    }

    private function resetOutput(): void
    {
        $normalized = str_replace('\\', '/', rtrim($this->output, '/\\'));
        $normalizedRoot = str_replace('\\', '/', rtrim($this->root, '/\\'));
        if ($normalized === '' || $normalized === '/' || preg_match('#^[A-Za-z]:$#', $normalized) === 1
            || strcasecmp($normalized, $normalizedRoot) === 0
            || str_starts_with(strtolower($normalizedRoot), strtolower($normalized) . '/')) {
            throw new RuntimeException('Unsafe bridge release output path.');
        }
        if (is_dir($this->output)) {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($this->output, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($iterator as $item) {
                $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
            }
            rmdir($this->output);
        }
        if (!mkdir($this->output, 0755, true) && !is_dir($this->output)) {
            throw new RuntimeException('Unable to create bridge release output.');
        }
    }

    private function countFiles(): int
    {
        $count = 0;
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->output, FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterator as $item) {
            if ($item->isFile()) {
                ++$count;
            }
        }
        return $count;
    }

    private function detectDirty(): bool
    {
        return $this->gitValue('status --porcelain', '') !== '';
    }

    private function gitValue(string $arguments, string $fallback): string
    {
        $nullDevice = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
        $command = 'git -C ' . escapeshellarg($this->root) . ' ' . $arguments . ' 2>' . $nullDevice;
        $value = shell_exec($command);
        return is_string($value) && trim($value) !== '' ? trim($value) : $fallback;
    }
}
