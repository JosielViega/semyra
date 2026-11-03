<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\Logger;
use App\Repositories\RoomRepository;
use App\Services\DesktopMediaPublishException;
use App\Services\DesktopMediaPublishService;
use Throwable;

final class RoomDesktopMediaPublishController
{
    private const HEADERS = ['Cache-Control' => 'no-store', 'Pragma' => 'no-cache', 'X-Content-Type-Options' => 'nosniff'];

    public function __construct(
        private readonly Request $request,
        private readonly RoomRepository $rooms,
        private readonly DesktopMediaPublishService $publish,
        private readonly ?Logger $logger = null,
    ) {
    }

    public function start(string $code): Response
    {
        return $this->handle($code, 'start');
    }

    public function stop(string $code): Response
    {
        return $this->handle($code, 'stop');
    }

    public function status(string $code): Response
    {
        return $this->handle($code, 'status');
    }

    private function handle(string $code, string $action = 'start'): Response
    {
        if ($action === 'start') {
            $this->logger?->info('Desktop media publish request reached PHP.', ['action' => $action]);
        }
        $room = $this->rooms->findByCode($code);
        if ($room === null) {
            return $this->error('room_not_found', 404, $action);
        }
        $contentType = strtolower(trim((string) $this->request->header('Content-Type', '')));
        if (!str_starts_with($contentType, 'application/json')) {
            return $this->error('invalid_request', 422, $action);
        }
        $body = $this->request->json();
        $expected = $action === 'start' ? ['transmission_instance_id', 'transmission_revision'] : ['ingress_id', 'transmission_instance_id', 'transmission_revision'];
        if ($body === null || count($body) !== count($expected) || array_diff(array_keys($body), $expected) !== []) {
            return $this->error('invalid_request', 422, $action);
        }
        $instanceId = $body['transmission_instance_id'] ?? null;
        $revision = $body['transmission_revision'] ?? null;
        if (!is_string($instanceId) || preg_match('/^[a-f0-9]{32}$/', $instanceId) !== 1 || !is_int($revision) || $revision < 1) {
            return $this->error('invalid_request', 422, $action);
        }
        $authorization = $this->request->header('Authorization', '');
        if (!is_string($authorization) || preg_match('/\ABearer ([a-f0-9]{32}\.[a-f0-9]{64})\z/D', $authorization, $matches) !== 1) {
            return $this->error('host_session_required', 401, $action);
        }
        try {
            if ($action !== 'start') {
                $ingressId = $body['ingress_id'] ?? null;
                if (!is_string($ingressId) || preg_match('/^[A-Za-z0-9_-]{1,128}$/', $ingressId) !== 1) {
                    return $this->error('invalid_request', 422, $action);
                }
                if ($action === 'status') {
                    return Response::json($this->publish->status($matches[1], (int) $room['id'], $instanceId, $revision, $ingressId), 200, self::HEADERS);
                }
                $this->publish->stop($matches[1], (int) $room['id'], $instanceId, $revision, $ingressId);
                return Response::json(['stopped' => true], 200, self::HEADERS);
            }
            $result = Response::json($this->publish->start($matches[1], (int) $room['id'], $instanceId, $revision), 201, self::HEADERS);
            $this->logger?->info('Desktop media publish request completed in PHP.', ['action' => $action, 'status' => 201]);
            return $result;
        } catch (DesktopMediaPublishException $exception) {
            return $this->error($exception->errorCode, $exception->status, $action);
        } catch (Throwable $exception) {
            $this->logger?->error('Desktop media publish request failed unexpectedly in PHP.', [
                'action' => $action,
                'exception' => $exception::class,
            ]);
            return $this->error('media_publish_failed', 503, $action);
        }
    }

    private function error(string $error, int $status, string $action): Response
    {
        if ($action === 'start') {
            $this->logger?->info('Desktop media publish request rejected in PHP.', [
                'action' => $action,
                'public_code' => $error,
                'status' => $status,
            ]);
        }
        return Response::json(['error' => $error], $status, self::HEADERS);
    }
}
