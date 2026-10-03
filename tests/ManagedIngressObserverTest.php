<?php

declare(strict_types=1);

namespace Tests;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Semyra\ManagedIngressObserver\TransitionRecorder;

require_once __DIR__ . '/manual/livekit-spike/managed-ingress-observer-lib.php';

final class ManagedIngressObserverTest extends TestCase
{
    public function testRecordsOnlySanitizedTransitionsFromFakeSamples(): void
    {
        $recorder = new TransitionRecorder();
        $private = 'must-not-appear.example/private';

        $first = $recorder->record(0, [
            'status' => 'inactive', 'video_mime' => 'video/H264', 'audio_mime' => 'audio/opus',
            'width' => 1920, 'height' => 1080, 'fps' => 30, 'url' => $private, 'ingress_id' => $private,
        ]);
        $duplicate = $recorder->record(1, [
            'status' => 'inactive', 'video_mime' => 'video/H264', 'audio_mime' => 'audio/opus',
            'width' => 1920, 'height' => 1080, 'fps' => 30, 'url' => 'different-private-value',
        ]);
        $publishing = $recorder->record(2.26, [
            'status' => 'publishing', 'video_mime' => 'video/H264', 'audio_mime' => 'audio/opus',
            'width' => 1920, 'height' => 1080, 'fps' => 29.97,
        ]);

        self::assertSame('T+0.0 status=inactive video=H264 audio=Opus width=1920 height=1080 fps=30', $first);
        self::assertNull($duplicate);
        self::assertSame('T+2.3 status=publishing video=H264 audio=Opus width=1920 height=1080 fps=29.97', $publishing);
        self::assertStringNotContainsString($private, $first . $publishing);
    }

    public function testRejectsStatusOutsideSafeAllowlist(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new TransitionRecorder())->record(0, ['status' => 'private-detail']);
    }

    public function testServerHarnessUsesExplicitLifecycleAndHealthCheck(): void
    {
        $script = (string) file_get_contents(__DIR__ . '/manual/livekit-spike/managed-smoke-server.ps1');

        self::assertStringContainsString("[ValidateSet('start', 'health', 'stop')]", $script);
        self::assertStringContainsString('/health', $script);
        self::assertStringContainsString('managed-smoke-server.pid', $script);
        self::assertStringContainsString('Start-Process', $script);
        self::assertStringContainsString('Stop-Process', $script);
        self::assertStringNotContainsString('Wait-Process', $script);
        self::assertStringNotContainsString('-Wait', $script);
    }
}
