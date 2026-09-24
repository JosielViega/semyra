<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;
use TemplateTools\ProjectSetup;
use TemplateTools\PortRegistry;

require_once dirname(__DIR__) . '/bin/lib/Port.php';
require_once dirname(__DIR__) . '/bin/lib/PortRegistry.php';
require_once dirname(__DIR__) . '/bin/lib/ProjectSetup.php';

final class ProjectSetupTest extends TestCase
{
    private string $directory;
    private PortRegistry $registry;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/modelo-php-setup-' . bin2hex(random_bytes(6));
        mkdir($this->directory, 0777, true);
        $this->registry = new PortRegistry($this->directory . '/registry/ports.json');
    }

    protected function tearDown(): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($this->directory);
    }

    public function testCreatesEnvironmentAndSelectsNextAvailablePort(): void
    {
        file_put_contents($this->directory . '/.env.example', "APP_NAME=Example\nAPP_URL=http://localhost:8010\nAPP_PORT=8010\n");
        $available = static fn (int $port): bool => $port === 8012;

        $result = (new ProjectSetup($this->directory, $available, $this->registry))->run();
        $environment = file_get_contents($this->directory . '/.env');

        self::assertTrue($result['created']);
        self::assertSame(8012, $result['port']);
        self::assertStringContainsString('APP_PORT=8012', $environment);
        self::assertStringContainsString('APP_URL=http://localhost:8012', $environment);
    }

    public function testPreservesExistingEnvironmentWhenPortIsAvailable(): void
    {
        $contents = "APP_NAME=Customized\nAPP_URL=https://example.test\nAPP_PORT=8123\nCUSTOM_VALUE=keep-me\n";
        file_put_contents($this->directory . '/.env.example', "APP_PORT=8010\n");
        file_put_contents($this->directory . '/.env', $contents);

        $result = (new ProjectSetup(
            $this->directory,
            static fn (int $port): bool => true,
            $this->registry,
        ))->run();

        self::assertFalse($result['created']);
        self::assertFalse($result['changed']);
        self::assertSame($contents, file_get_contents($this->directory . '/.env'));
    }

    public function testRegisteredPortWinsAndSetupIsIdempotent(): void
    {
        file_put_contents($this->directory . '/.env.example', "APP_URL=http://localhost:8010\nAPP_PORT=8010\n");
        $this->registry->assign($this->directory, 8012, static fn (int $port): bool => true);

        $first = (new ProjectSetup(
            $this->directory,
            static fn (int $port): bool => true,
            $this->registry,
        ))->run();
        $afterFirst = file_get_contents($this->directory . '/.env');
        $second = (new ProjectSetup(
            $this->directory,
            static fn (int $port): bool => true,
            $this->registry,
        ))->run();

        self::assertSame(8012, $first['port']);
        self::assertSame(8012, $second['port']);
        self::assertSame($afterFirst, file_get_contents($this->directory . '/.env'));
        self::assertStringContainsString('APP_URL=http://localhost:8012', $afterFirst);
    }
}
