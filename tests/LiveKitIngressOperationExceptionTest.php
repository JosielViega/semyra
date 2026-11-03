<?php

declare(strict_types=1);

namespace Tests;

use App\Services\LiveKitIngressOperationException;
use Livekit\TwirpError;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Twirp\ErrorCode;

final class LiveKitIngressOperationExceptionTest extends TestCase
{
    #[DataProvider('twirpFailures')]
    public function testMapsStructuredApiFailuresWithoutExposingUpstreamMessage(
        string $twirpCode,
        string $publicCode,
        string $diagnosticCode,
        int $status,
    ): void {
        $failure = LiveKitIngressOperationException::from(
            'create',
            TwirpError::newError($twirpCode, 'sensitive upstream response with credential-like data'),
        );

        self::assertSame($publicCode, $failure->publicCode);
        self::assertSame($diagnosticCode, $failure->diagnosticCode);
        self::assertSame($status, $failure->status);
        self::assertSame($publicCode, $failure->getMessage());
        self::assertStringNotContainsString('sensitive', $failure->getMessage());
    }

    public static function twirpFailures(): array
    {
        return [
            'quota' => [ErrorCode::ResourceExhausted, 'ingress_quota_exceeded', 'livekit_quota_exhausted', 429],
            'unauthenticated' => [ErrorCode::Unauthenticated, 'ingress_api_unauthorized', 'livekit_api_unauthenticated', 401],
            'forbidden' => [ErrorCode::PermissionDenied, 'ingress_api_forbidden', 'livekit_api_permission_denied', 403],
            'timeout' => [ErrorCode::DeadlineExceeded, 'ingress_api_timeout', 'livekit_api_timeout', 504],
            'unavailable' => [ErrorCode::Unavailable, 'ingress_api_unavailable', 'livekit_api_unavailable', 503],
        ];
    }

    #[DataProvider('transportFailures')]
    public function testClassifiesTransportFailuresForProtectedOperationalLogs(
        string $message,
        string $publicCode,
        string $diagnosticCode,
    ): void {
        $failure = LiveKitIngressOperationException::from('create', new RuntimeException($message));

        self::assertSame($publicCode, $failure->publicCode);
        self::assertSame($diagnosticCode, $failure->diagnosticCode);
    }

    public static function transportFailures(): array
    {
        return [
            'dns' => ['getaddrinfo failed for private-host.invalid', 'ingress_api_dns_failed', 'livekit_api_dns_failed'],
            'tls' => ['TLS certificate verification failed', 'ingress_api_tls_failed', 'livekit_api_tls_failed'],
            'timeout' => ['connection timed out', 'ingress_api_timeout', 'livekit_api_timeout'],
            'http 401' => ['HTTP status code 401', 'ingress_api_unauthorized', 'livekit_api_unauthenticated'],
            'http 403' => ['HTTP 403 response', 'ingress_api_forbidden', 'livekit_api_permission_denied'],
            'http 429' => ['status 429 returned', 'ingress_quota_exceeded', 'livekit_quota_exhausted'],
        ];
    }

    #[DataProvider('operationFailures')]
    public function testKeepsOperationPhaseForUnstructuredFailures(string $operation, string $publicCode): void
    {
        $failure = LiveKitIngressOperationException::from($operation, new RuntimeException('opaque failure'));
        self::assertSame($publicCode, $failure->publicCode);
        self::assertSame($operation, $failure->operation);
    }

    public static function operationFailures(): array
    {
        return [
            'list' => ['list', 'ingress_list_failed'],
            'delete' => ['delete', 'ingress_cleanup_failed'],
            'create' => ['create', 'ingress_create_failed'],
            'status' => ['status', 'ingress_status_failed'],
        ];
    }
}
