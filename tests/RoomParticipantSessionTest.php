<?php

declare(strict_types=1);

namespace Tests;

use App\Core\Session;
use App\Services\RoomParticipantSession;
use PHPUnit\Framework\TestCase;

final class RoomParticipantSessionTest extends TestCase
{
    private RoomParticipantSession $participants;

    protected function setUp(): void
    {
        $_SESSION = [];
        $this->participants = new RoomParticipantSession(new Session(false));
    }

    public function testCreatesARealRandomIdentityForEachRoom(): void
    {
        $first = $this->participants->remember('ABCDEFGH', 'Josiel');
        $second = $this->participants->remember('HGFEDCBA', 'Pedro');

        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $first['participant_key']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $second['participant_key']);
        self::assertNotSame($first['participant_key'], $second['participant_key']);
        self::assertSame('Josiel', $first['display_name']);
        self::assertSame('Pedro', $second['display_name']);
    }

    public function testReusesTheRoomIdentityAndUpdatesItsDisplayName(): void
    {
        $first = $this->participants->remember('ABCDEFGH', 'Josiel');
        $updated = $this->participants->remember('ABCDEFGH', 'Josie');

        self::assertSame($first['participant_key'], $updated['participant_key']);
        self::assertSame('Josie', $updated['display_name']);
        self::assertSame($updated, $this->participants->identityFor('ABCDEFGH'));
    }

    public function testRejectsMalformedSessionData(): void
    {
        $_SESSION['room_participants']['ABCDEFGH'] = [
            'participant_key' => 'predictable',
            'display_name' => 'Josiel',
        ];

        self::assertNull($this->participants->identityFor('ABCDEFGH'));
    }
}
