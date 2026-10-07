<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Repositories\RoomRepository;
use App\Services\DesktopMediaPublishException;
use App\Services\DesktopMediaPublishService;
use Throwable;

final class RoomDesktopMediaPublishController
{
    private const HEADERS = ['Cache-Control' => 'no-store', 'Pragma' => 'no-cache', 'X-Content-Type-Options' => 'nosniff'];

    public function __construct(private readonly Request $request, private readonly RoomRepository $rooms, private readonly DesktopMediaPublishService $publish)
    {
    }

    public function start(string $code): Response
    {
        return $this->handle($code, false);
    }

    public function stop(string $code): Response
    {
        return $this->handle($code, true);
    }

    private function handle(string $code, bool $stop): Response
    {
        $room = $this->rooms->findByCode($code);
        if ($room === null) {
            return $this->error('room_not_found', 404);
        }
        $contentType = strtolower(trim((string) $this->request->header('Content-Type', '')));
        if (!str_starts_with($contentType, 'application/json')) {
            return $this->error('invalid_request', 422);
        }
        $body = $this->request->json();
        $expected = $stop ? ['ingress_id', 'transmission_instance_id', 'transmission_revision'] : ['transmission_instance_id', 'transmission_revision'];
        if ($body === null || count($body) !== count($expected) || array_diff(array_keys($body), $expected) !== []) {
            return $this->error('invalid_request', 422);
        }
        $instanceId = $body['transmission_instance_id'] ?? null;
        $revision = $body['transmission_revision'] ?? null;
        if (!is_string($instanceId) || preg_match('/^[a-f0-9]{32}$/', $instanceId) !== 1 || !is_int($revision) || $revision < 1) {
            return $this->error('invalid_request', 422);
        }
        $authorization = $this->request->header('Authorization', '');
        if (!is_string($authorization) || preg_match('/\ABearer ([a-f0-9]{32}\.[a-f0-9]{64})\z/D', $authorization, $matches) !== 1) {
            return $this->error('host_session_required', 401);
        }
        try {
            if ($stop) {
                $ingressId = $body['ingress_id'] ?? null;
                if (!is_string($ingressId) || preg_match('/^[A-Za-z0-9_-]{1,128}$/', $ingressId) !== 1) {
                    return $this->error('invalid_request', 422);
                }
                $this->publish->stop($matches[1], (int) $room['id'], $instanceId, $revision, $ingressId);
                return Response::json(['stopped' => true], 200, self::HEADERS);
            }
            return Response::json($this->publish->start($matches[1], (int) $room['id'], $instanceId, $revision), 201, self::HEADERS);
        } catch (DesktopMediaPublishException $exception) {
            return $this->error($exception->errorCode, $exception->status);
        } catch (Throwable) {
            return $this->error('media_publish_failed', 503);
        }
    }

    private function error(string $error, int $status): Response
    {
        return Response::json(['error' => $error], $status, self::HEADERS);
    }
}
