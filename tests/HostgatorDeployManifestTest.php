<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;

final class HostgatorDeployManifestTest extends TestCase
{
    private array $manifest;

    protected function setUp(): void
    {
        $this->manifest = require dirname(__DIR__) . '/deploy/hostgator/config/deploy.php';
    }

    public function testProductionAllowlistContainsRequiredApplicationItems(): void
    {
        foreach (['app', 'bootstrap', 'config', 'public/index.php', 'resources', 'routes', 'composer.json', 'composer.lock'] as $item) {
            self::assertContains($item, $this->manifest['include']);
        }
    }

    public function testSensitiveAndServerOwnedItemsAreProtected(): void
    {
        foreach (['.env', '.env.example', '.htaccess', '.user.ini', 'php.ini', 'error_log'] as $item) {
            self::assertContains($item, $this->manifest['protected_names']);
        }

        foreach (['tests', 'public/uploads', 'storage/logs', 'storage/cache', 'vendor'] as $item) {
            self::assertContains($item, $this->manifest['ignore']);
        }
    }

    public function testCertificateAndLogExtensionsAreProtected(): void
    {
        foreach (['log', 'pem', 'key', 'crt', 'p12', 'pfx'] as $extension) {
            self::assertContains($extension, $this->manifest['protected_extensions']);
        }
    }
}
