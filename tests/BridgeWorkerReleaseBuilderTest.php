<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use TemplateTools\BridgeWorkerReleaseBuilder;

require_once dirname(__DIR__) . '/bin/lib/BridgeWorkerReleaseBuilder.php';

final class BridgeWorkerReleaseBuilderTest extends TestCase
{
    private string $output;

    protected function setUp(): void
    {
        $this->output = sys_get_temp_dir() . '/semyra-bridge-release-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->output)) {
            return;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->output, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($this->output);
    }

    public function testBuildsExplicitRuntimeAndOperationsAllowlist(): void
    {
        $result = (new BridgeWorkerReleaseBuilder(
            dirname(__DIR__),
            $this->output,
            str_repeat('a', 40),
            false,
            '2026-10-04T00:00:00Z',
        ))->build();

        self::assertGreaterThan(10, $result['files']);
        self::assertFileExists($this->output . '/worker.php');
        self::assertFileExists($this->output . '/bootstrap.php');
        self::assertFileExists($this->output . '/src/WorkerRunner.php');
        self::assertFileExists($this->output . '/ops/systemd/semyra-bridge.service');
        self::assertFileExists($this->output . '/ops/systemd/bridge-worker.env.example');
        self::assertFileExists($this->output . '/build-info.json');

        $metadata = json_decode((string) file_get_contents($this->output . '/build-info.json'), true);
        self::assertSame(str_repeat('a', 40), $metadata['source_sha']);
        self::assertFalse($metadata['working_tree_dirty']);
        self::assertTrue($metadata['publishable']);
    }

    public function testReleaseExcludesPrivateRuntimeAndDevelopmentFiles(): void
    {
        (new BridgeWorkerReleaseBuilder(dirname(__DIR__), $this->output, str_repeat('b', 40), true))->build();

        $relativeFiles = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->output, FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterator as $item) {
            if ($item->isFile()) {
                $relativeFiles[] = str_replace('\\', '/', substr($item->getPathname(), strlen($this->output) + 1));
            }
        }
        $manifest = '/' . implode("\n/", $relativeFiles);
        self::assertStringNotContainsString('/.private/', $manifest);
        self::assertStringNotContainsString('/runtime/', $manifest);
        self::assertStringNotContainsString('/tests/', $manifest);
        self::assertStringNotContainsString('/.env', $manifest);
        self::assertStringNotContainsString('bridge-sources.json', $manifest);
        self::assertStringNotContainsString('source-catalog.json', $manifest);
    }
}
