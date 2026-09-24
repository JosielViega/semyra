<?php

declare(strict_types=1);

namespace Tests;

use App\Services\RoomCodeGenerator;
use PHPUnit\Framework\TestCase;

final class RoomCodeGeneratorTest extends TestCase
{
    public function testGeneratesCodesWithExpectedLengthAndAlphabet(): void
    {
        $generator = new RoomCodeGenerator();

        for ($sample = 0; $sample < 100; ++$sample) {
            $code = $generator->generate();

            self::assertSame(8, strlen($code));
            self::assertMatchesRegularExpression('/^[ABCDEFGHJKLMNPQRSTUVWXYZ23456789]{8}$/', $code);
            self::assertDoesNotMatchRegularExpression('/[IO01]/', $code);
        }
    }
}
