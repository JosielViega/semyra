<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Semyra\BridgeWorker\MediaExitStatus;

require_once __DIR__ . '/../bridge-worker/bootstrap.php';

final class BridgeWorkerMediaExitStatusTest extends TestCase
{
    private string $statusPath;

    protected function setUp(): void
    {
        $this->statusPath = sys_get_temp_dir() . '/semyra-media-status-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        @unlink($this->statusPath);
    }

    public function testSourceExitMapsToSanitizedSourceFailure(): void
    {
        $reason = MediaExitStatus::classify(false, true);

        self::assertSame(MediaExitStatus::SOURCE_EXITED, $reason);
        self::assertTrue(MediaExitStatus::write($this->statusPath, $reason));
        self::assertSame('source_failed', MediaExitStatus::errorCode(MediaExitStatus::read($this->statusPath)));
    }

    public function testPipelineExitMapsToSanitizedPipelineFailure(): void
    {
        $reason = MediaExitStatus::classify(true, false);

        self::assertSame(MediaExitStatus::PIPELINE_EXITED, $reason);
        self::assertTrue(MediaExitStatus::write($this->statusPath, $reason));
        self::assertSame('pipeline_failed', MediaExitStatus::errorCode(MediaExitStatus::read($this->statusPath)));
    }

    public function testAmbiguousExitMapsToSafeGenericFailure(): void
    {
        $reason = MediaExitStatus::classify(false, false);

        self::assertSame(MediaExitStatus::SOURCE_AND_PIPELINE_EXITED, $reason);
        self::assertSame('pipeline_failed', MediaExitStatus::errorCode($reason));
    }

    public function testMissingOrInvalidStatusMapsToSafeGenericFailure(): void
    {
        self::assertSame(MediaExitStatus::HELPER_FAILED, MediaExitStatus::read($this->statusPath));
        self::assertSame('pipeline_failed', MediaExitStatus::errorCode(MediaExitStatus::read($this->statusPath)));
        self::assertFalse(MediaExitStatus::write($this->statusPath, 'private-detail'));
    }
}
