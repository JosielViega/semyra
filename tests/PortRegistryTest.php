<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use TemplateTools\PortRegistry;

require_once dirname(__DIR__) . '/bin/lib/Port.php';
require_once dirname(__DIR__) . '/bin/lib/PortRegistry.php';

final class PortRegistryTest extends TestCase
{
    private string $directory;
    private PortRegistry $registry;
    private string $projectA;
    private string $projectB;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/modelo-php-registry-' . bin2hex(random_bytes(6));
        $this->projectA = $this->directory . '/clients/site';
        $this->projectB = $this->directory . '/personal/site';
        mkdir($this->projectA, 0777, true);
        mkdir($this->projectB, 0777, true);
        $this->registry = new PortRegistry($this->directory . '/state/ports.json');
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->directory)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($this->directory);
    }

    public function testNewProjectReceivesPreferredFreePort(): void
    {
        $result = $this->registry->assign($this->projectA, 8010, static fn (int $port): bool => true);

        self::assertSame(8010, $result['port']);
        self::assertSame(8010, $this->registry->reservationFor($this->projectA));
    }

    public function testReservationForAnotherOfflineProjectIsSkipped(): void
    {
        $this->registry->assign($this->projectA, 8010, static fn (int $port): bool => true);
        $result = $this->registry->assign($this->projectB, 8010, static fn (int $port): bool => true);

        self::assertSame(8011, $result['port']);
    }

    public function testActiveListenerPortIsSkipped(): void
    {
        $result = $this->registry->assign(
            $this->projectA,
            8010,
            static fn (int $port): bool => $port !== 8010,
        );

        self::assertSame(8011, $result['port']);
    }

    public function testExistingProjectKeepsItsAvailableReservation(): void
    {
        $this->registry->assign($this->projectA, 8012, static fn (int $port): bool => true);
        $result = $this->registry->assign($this->projectA, 8010, static fn (int $port): bool => true);

        self::assertSame(8012, $result['port']);
        self::assertFalse($result['created']);
    }

    public function testCorruptedRegistryFailsWithoutBeingOverwritten(): void
    {
        mkdir(dirname($this->registry->path()), 0777, true);
        file_put_contents($this->registry->path(), '{broken-json');
        $before = hash_file('sha256', $this->registry->path());

        try {
            $this->registry->assign($this->projectA, 8010, static fn (int $port): bool => true);
            self::fail('A corrupted registry should fail.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('invalid JSON', $exception->getMessage());
            self::assertSame($before, hash_file('sha256', $this->registry->path()));
        }
    }

    public function testClearlyMissingProjectReservationIsRemoved(): void
    {
        $missing = $this->directory . '/clients/removed-project';
        mkdir(dirname($this->registry->path()), 0777, true);
        file_put_contents($this->registry->path(), json_encode([
            'version' => 1,
            'projects' => [
                $this->registry->normalizeProjectPath($missing) => ['port' => 8010],
            ],
        ], JSON_THROW_ON_ERROR));

        $result = $this->registry->assign($this->projectA, 8010, static fn (int $port): bool => true);
        $saved = json_decode((string) file_get_contents($this->registry->path()), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(8010, $result['port']);
        self::assertSame(1, $result['removedStale']);
        self::assertCount(1, $saved['projects']);
    }

    public function testProjectsWithSameFolderNameRemainDistinct(): void
    {
        $first = $this->registry->assign($this->projectA, 8010, static fn (int $port): bool => true);
        $second = $this->registry->assign($this->projectB, 8010, static fn (int $port): bool => true);

        self::assertSame(8010, $first['port']);
        self::assertSame(8011, $second['port']);
        self::assertNotSame(
            $this->registry->normalizeProjectPath($this->projectA),
            $this->registry->normalizeProjectPath($this->projectB),
        );
    }

    public function testPathNormalizationAvoidsSeparatorAndDotDuplicates(): void
    {
        $first = $this->registry->normalizeProjectPath('C:\\Projects\\site\\..\\site\\');
        $second = $this->registry->normalizeProjectPath('C:/Projects/site/');

        self::assertSame($first, $second);
    }

    public function testReleaseRemovesOnlyCurrentProject(): void
    {
        $this->registry->assign($this->projectA, 8010, static fn (int $port): bool => true);
        $this->registry->assign($this->projectB, 8011, static fn (int $port): bool => true);

        self::assertSame(8010, $this->registry->release($this->projectA));
        self::assertNull($this->registry->reservationFor($this->projectA));
        self::assertSame(8011, $this->registry->reservationFor($this->projectB));
    }
}
