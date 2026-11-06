<?php

declare(strict_types=1);

namespace App\Services;

use Throwable;
use Twirp\Error;
use Twirp\ErrorCode;

final class LiveKitIngressOperationException extends \RuntimeException
{
    public function __construct(
        public readonly string $operation,
        public readonly string $diagnosticCode,
        public readonly string $publicCode,
        public readonly int $status,
        Throwable $previous,
    ) {
        parent::__construct($publicCode, 0, $previous);
    }

    public static function from(string $operation, Throwable $exception): self
    {
        $twirpCode = $exception instanceof Error ? $exception->getErrorCode() : null;
        [$diagnostic, $public, $status] = match ($twirpCode) {
            ErrorCode::ResourceExhausted => ['livekit_quota_exhausted', 'ingress_quota_exceeded', 429],
            ErrorCode::Unauthenticated => ['livekit_api_unauthenticated', 'ingress_api_unauthorized', 401],
            ErrorCode::PermissionDenied => ['livekit_api_permission_denied', 'ingress_api_forbidden', 403],
            ErrorCode::DeadlineExceeded => ['livekit_api_timeout', 'ingress_api_timeout', 504],
            ErrorCode::Unavailable => ['livekit_api_unavailable', 'ingress_api_unavailable', 503],
            default => self::transportOrOperationFailure($operation, $exception),
        };

        return new self($operation, $diagnostic, $public, $status, $exception);
    }

    /** @return array{string,string,int} */
    private static function transportOrOperationFailure(string $operation, Throwable $exception): array
    {
        $text = '';
        for ($current = $exception; $current !== null; $current = $current->getPrevious()) {
            $text .= ' ' . strtolower($current->getMessage());
        }
        if (str_contains($text, 'could not resolve') || str_contains($text, 'getaddrinfo')
            || str_contains($text, 'name or service not known')) {
            return ['livekit_api_dns_failed', 'ingress_api_dns_failed', 503];
        }
        if (str_contains($text, 'certificate') || str_contains($text, 'ssl') || str_contains($text, 'tls')) {
            return ['livekit_api_tls_failed', 'ingress_api_tls_failed', 503];
        }
        if (str_contains($text, 'timed out') || str_contains($text, 'timeout')) {
            return ['livekit_api_timeout', 'ingress_api_timeout', 504];
        }
        if (self::containsHttpStatus($text, 401)) {
            return ['livekit_api_unauthenticated', 'ingress_api_unauthorized', 401];
        }
        if (self::containsHttpStatus($text, 403)) {
            return ['livekit_api_permission_denied', 'ingress_api_forbidden', 403];
        }
        if (self::containsHttpStatus($text, 429)) {
            return ['livekit_quota_exhausted', 'ingress_quota_exceeded', 429];
        }

        return match ($operation) {
            'list' => ['livekit_ingress_list_failed', 'ingress_list_failed', 503],
            'delete' => ['livekit_ingress_cleanup_failed', 'ingress_cleanup_failed', 503],
            'status' => ['livekit_ingress_status_failed', 'ingress_status_failed', 503],
            'create' => ['livekit_ingress_create_failed', 'ingress_create_failed', 503],
            default => ['livekit_api_transport_failed', 'ingress_api_transport_failed', 503],
        };
    }

    private static function containsHttpStatus(string $text, int $status): bool
    {
        $matched = preg_match_all(
            '/(?:\bhttp\b|\bstatus(?:\s+code)?\b)\D{0,12}([0-9]{3})(?![0-9])/i',
            $text,
            $matches,
        );

        return $matched !== false && in_array((string) $status, $matches[1], true);
    }
}
