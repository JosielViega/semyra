<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Services\MediaBridgeCoordinator;
use App\Services\MediaBridgeProtocolException;
use App\Services\MediaBridgeWorkerAuthenticator;
use Throwable;

final class MediaBridgeController
{
    private const HEADERS = [
        'Cache-Control' => 'no-store',
        'Pragma' => 'no-cache',
        'X-Content-Type-Options' => 'nosniff',
    ];

    public function __construct(
        private readonly Request $request,
        private readonly MediaBridgeWorkerAuthenticator $authenticator,
        private readonly MediaBridgeCoordinator $coordinator,
    ) {
    }

    public function claim(): Response
    {
        if (!$this->authenticator->accepts($this->request)) {
            return $this->error('worker_unauthorized', 401);
        }
        try {
            $job = $this->coordinator->claim($this->string('worker_id'));
            return $job === null
                ? new Response('', 204, self::HEADERS)
                : Response::json($job, 201, self::HEADERS);
        } catch (MediaBridgeProtocolException $exception) {
            return $this->error($exception->error, $exception->status);
        } catch (Throwable) {
            return $this->error('bridge_unavailable', 503);
        }
    }

    public function heartbeat(): Response
    {
        return $this->handle(static fn (self $self): array => $self->coordinator->heartbeat(
            $self->positiveInteger('job_id'),
            $self->string('transmission_instance_id'),
            $self->string('worker_id'),
            $self->string('lease_token'),
            $self->string('status'),
        ));
    }

    public function report(): Response
    {
        return $this->handle(static fn (self $self): array => $self->coordinator->report(
            $self->positiveInteger('job_id'),
            $self->string('transmission_instance_id'),
            $self->string('worker_id'),
            $self->string('lease_token'),
            $self->string('status'),
            $self->nullableString('error_code'),
        ));
    }

    /** @param callable(self): array $operation */
    private function handle(callable $operation): Response
    {
        if (!$this->authenticator->accepts($this->request)) {
            return $this->error('worker_unauthorized', 401);
        }
        try {
            return Response::json($operation($this), 200, self::HEADERS);
        } catch (MediaBridgeProtocolException $exception) {
            return $this->error($exception->error, $exception->status);
        } catch (Throwable) {
            return $this->error('bridge_unavailable', 503);
        }
    }

    private function string(string $key): string
    {
        $value = $this->request->input($key);
        if (!is_string($value) || trim($value) === '') {
            throw new MediaBridgeProtocolException('invalid_worker_request', 422);
        }

        return trim($value);
    }

    private function nullableString(string $key): ?string
    {
        $value = $this->request->input($key);
        return $value === null || $value === '' ? null : $this->string($key);
    }

    private function positiveInteger(string $key): int
    {
        $value = $this->request->input($key);
        if (!is_string($value) || preg_match('/^[1-9][0-9]*$/', $value) !== 1) {
            throw new MediaBridgeProtocolException('invalid_worker_request', 422);
        }

        return (int) $value;
    }

    private function error(string $error, int $status): Response
    {
        return Response::json(['error' => $error], $status, self::HEADERS);
    }
}
