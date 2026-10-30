<?php

declare(strict_types=1);

namespace Tests;

use App\Core\Database;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class DatabaseTest extends TestCase
{
    public function testConnectionsInitializeTheMysqlSessionInUtc(): void
    {
        $method = new ReflectionMethod(Database::class, 'connectionOptions');
        $options = $method->invoke(new Database([]));

        self::assertContains("SET time_zone = '+00:00'", $options);
    }
}
