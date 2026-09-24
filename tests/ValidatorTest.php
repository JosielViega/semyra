<?php

declare(strict_types=1);

namespace Tests;

use App\Validation\Validator;
use PHPUnit\Framework\TestCase;

final class ValidatorTest extends TestCase
{
    public function testAcceptsValidData(): void
    {
        $validator = new Validator();

        self::assertTrue($validator->validate(
            ['email' => 'person@example.com', 'age' => '21'],
            ['email' => 'required|email|max:120', 'age' => 'required|integer'],
        ));
        self::assertSame([], $validator->errors());
    }

    public function testCollectsValidationErrors(): void
    {
        $validator = new Validator();

        self::assertFalse($validator->validate(
            ['message' => 'x'],
            ['message' => 'required|string|min:2'],
        ));
        self::assertArrayHasKey('message', $validator->errors());
    }
}
