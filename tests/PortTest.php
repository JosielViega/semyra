<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;
use TemplateTools\Port;

require_once dirname(__DIR__) . '/bin/lib/Port.php';

final class PortTest extends TestCase
{
    public function testDetectsAnExistingListenerWithoutStoppingIt(): void
    {
        $listener = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
        self::assertIsResource($listener, $errorMessage);

        $address = stream_socket_get_name($listener, false);
        $port = (int) substr((string) $address, strrpos((string) $address, ':') + 1);

        self::assertFalse(Port::isAvailable($port));
        self::assertIsResource($listener);
        fclose($listener);
    }

    public function testFindsFirstAvailablePortInOrder(): void
    {
        $port = Port::findAvailable(8010, 8013, static fn (int $candidate): bool => $candidate === 8012);

        self::assertSame(8012, $port);
    }
}
