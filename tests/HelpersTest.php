<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;

final class HelpersTest extends TestCase
{
    public function testEscapesHtmlByDefault(): void
    {
        self::assertSame('&lt;script&gt;&quot;x&quot;&lt;/script&gt;', e('<script>"x"</script>'));
    }
}
