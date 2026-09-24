<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use TemplateTools\HostgatorMirrorBuilder;

require_once dirname(__DIR__) . '/bin/lib/HostgatorMirrorBuilder.php';

final class HostgatorMirrorBuilderTest extends TestCase
{
    private string $root;
    private string $mirror;
    private array $manifest;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/modelo-php-mirror-' . bin2hex(random_bytes(6));
        $this->mirror = $this->root . '/deploy/hostgator/mirror';
        $this->manifest = require dirname(__DIR__) . '/deploy/hostgator/config/deploy.php';

        foreach ([
            'app/.keep',
            'bootstrap/app.php',
            'config/app.php',
            'config/database.php',
            'public/index.php',
            'resources/views/.keep',
            'routes/web.php',
            'composer.json',
            'composer.lock',
            'vendor/autoload.php',
        ] as $file) {
            $this->write($file, 'safe');
        }
        $this->write('vendor/composer/installed.json', json_encode(['packages' => []], JSON_THROW_ON_ERROR));
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->root)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($this->root);
    }

    public function testAcceptsCompleteProductionMirror(): void
    {
        $this->builder()->validate();
        self::assertFileExists($this->mirror . '/vendor/autoload.php');
        self::assertFileExists($this->mirror . '/composer.lock');
    }

    #[DataProvider('protectedPathProvider')]
    public function testRejectsProtectedPaths(string $path): void
    {
        $this->write($path, 'must not deploy');

        $this->expectException(RuntimeException::class);
        $this->builder()->validate();
    }

    public static function protectedPathProvider(): array
    {
        return [
            'environment' => ['.env'],
            'environment example' => ['.env.example'],
            'Apache rules' => ['public/.htaccess'],
            'user ini' => ['.user.ini'],
            'PHP ini' => ['php.ini'],
            'uploads' => ['public/uploads/user-file.txt'],
            'logs' => ['storage/logs/production.txt'],
            'cache' => ['storage/cache/item.txt'],
        ];
    }

    public function testRejectsPhpUnitInProductionVendor(): void
    {
        $this->write('vendor/composer/installed.json', json_encode([
            'packages' => [['name' => 'phpunit/phpunit']],
        ], JSON_THROW_ON_ERROR));

        $this->expectException(RuntimeException::class);
        $this->builder()->validate();
    }

    private function builder(): HostgatorMirrorBuilder
    {
        return new HostgatorMirrorBuilder($this->root, $this->manifest, 'composer');
    }

    private function write(string $relativePath, string $contents): void
    {
        $path = $this->mirror . '/' . $relativePath;
        $directory = dirname($path);
        if (!is_dir($directory)) {
            mkdir($directory, 0777, true);
        }
        file_put_contents($path, $contents);
    }
}
