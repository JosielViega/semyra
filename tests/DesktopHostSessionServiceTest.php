<?php

declare(strict_types=1);

namespace Tests;

use App\Core\Database;
use App\Repositories\DesktopHostSessionRepository;
use App\Services\DesktopHostSessionService;
use PHPUnit\Framework\TestCase;
use Tests\Support\DesktopHostSessionPdo;

final class DesktopHostSessionServiceTest extends TestCase
{
    private const INSTANCE = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const OWNER_HASH = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    public function testIssueStoresOnlyValidatorHashAndValidatesEveryBinding(): void
    {
        [$service, $pdo] = $this->service();
        $issued = $service->issue(7, self::INSTANCE, 3, null, self::OWNER_HASH);

        self::assertNotNull($issued);
        [$selector, $validator] = explode('.', $issued['token'], 2);
        self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $selector);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $validator);
        self::assertSame(hash('sha256', $validator), $pdo->sessions[$selector]['validator_hash']);
        self::assertNotSame($validator, $pdo->sessions[$selector]['validator_hash']);
        self::assertNotNull($service->validate($issued['token'], 7, self::INSTANCE, 3, 'media.publish'));
        self::assertNull($service->validate($selector . '.' . str_repeat('0', 64), 7, self::INSTANCE, 3, 'media.publish'));
        self::assertNull($service->validate($issued['token'], 8, self::INSTANCE, 3, 'media.publish'));
        self::assertNull($service->validate($issued['token'], 7, str_repeat('c', 32), 3, 'media.publish'));
        self::assertNull($service->validate($issued['token'], 7, self::INSTANCE, 4, 'media.publish'));
        self::assertNull($service->validate($issued['token'], 7, self::INSTANCE, 3, 'admin'));
    }

    public function testExpiredAndRevokedSessionsAreRejectedAndExpiredRowsAreDeleted(): void
    {
        [$service, $pdo, $repository] = $this->service();
        $expired = $service->issue(7, self::INSTANCE, 3, null, self::OWNER_HASH);
        self::assertNotNull($expired);
        [$expiredSelector] = explode('.', $expired['token'], 2);
        $pdo->sessions[$expiredSelector]['expired'] = true;
        self::assertNull($service->validate($expired['token'], 7, self::INSTANCE, 3, 'media.publish'));
        self::assertSame(1, $repository->deleteExpired());

        $revoked = $service->issue(7, self::INSTANCE, 3, null, self::OWNER_HASH);
        self::assertNotNull($revoked);
        [$selector] = explode('.', $revoked['token'], 2);
        self::assertTrue($repository->revokeBySelector($selector));
        self::assertNull($service->validate($revoked['token'], 7, self::INSTANCE, 3, 'media.publish'));
    }

    public function testNewEquivalentIssueRevokesPreviousAndAtomicCreateRejectsChangedTransmission(): void
    {
        [$service, $pdo] = $this->service();
        $first = $service->issue(7, self::INSTANCE, 3, null, self::OWNER_HASH);
        $second = $service->issue(7, self::INSTANCE, 3, null, self::OWNER_HASH);
        self::assertNotNull($first);
        self::assertNotNull($second);
        [$firstSelector] = explode('.', $first['token'], 2);
        self::assertNotNull($pdo->sessions[$firstSelector]['revoked_at']);

        $pdo->changeBeforeHostInsert = true;
        self::assertNull($service->issue(7, self::INSTANCE, 3, null, self::OWNER_HASH));
    }

    public function testMigrationContainsProtectedSchemaAndNoRawTokenColumn(): void
    {
        $migration = file_get_contents(
            dirname(__DIR__) . '/database/migrations/2026_10_06_000015_create_desktop_host_sessions.sql',
        );
        self::assertStringContainsString('selector CHAR(32) CHARACTER SET ascii COLLATE ascii_bin', $migration);
        self::assertStringContainsString('validator_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin', $migration);
        self::assertStringContainsString('UNIQUE KEY desktop_host_sessions_selector_unique (selector)', $migration);
        self::assertStringContainsString('FOREIGN KEY (room_id) REFERENCES rooms (id) ON DELETE CASCADE', $migration);
        self::assertStringContainsString('FOREIGN KEY (owner_user_id) REFERENCES users (id) ON DELETE CASCADE', $migration);
        self::assertStringNotContainsString('host_session_token', $migration);
        self::assertStringNotContainsString('validator CHAR', $migration);
    }

    /** @return array{DesktopHostSessionService, DesktopHostSessionPdo, DesktopHostSessionRepository} */
    private function service(): array
    {
        $pdo = new DesktopHostSessionPdo();
        $pdo->transmission = [
            'room_id' => 7,
            'instance_id' => self::INSTANCE,
            'revision' => 3,
            'owner_user_id' => null,
            'owner_participant_key_hash' => self::OWNER_HASH,
            'source_type' => 'iptv',
            'media_mode' => 'live',
        ];
        $database = new Database([]);
        (new \ReflectionProperty($database, 'connection'))->setValue($database, $pdo);
        $repository = new DesktopHostSessionRepository($database);

        return [new DesktopHostSessionService($repository, 600), $pdo, $repository];
    }
}
