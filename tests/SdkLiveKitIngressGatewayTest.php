<?php

declare(strict_types=1);

namespace Tests;

use Agence104\LiveKit\IngressServiceClient;
use App\Services\SdkLiveKitIngressGateway;
use Livekit\IngressInfo;
use Livekit\IngressInput;
use Livekit\ListIngressResponse;
use Livekit\TwirpError;
use PHPUnit\Framework\TestCase;
use Twirp\ErrorCode;

final class SdkLiveKitIngressGatewayTest extends TestCase
{
    public function testConvertsWebsocketUrlAndCreatesWhipWithoutTranscoding(): void
    {
        $client = new RecordingIngressClient();
        $gateway = new SdkLiveKitIngressGateway($this->config(), $client);
        $result = $gateway->createWhipIngress('smy_b_' . str_repeat('a', 32), 'smy_r_room', 'smy_i_publisher');

        self::assertSame('https://unit-test.invalid', $gateway->apiUrl());
        self::assertSame(IngressInput::WHIP_INPUT, $client->createArgs[0]);
        self::assertSame('smy_r_room', $client->createArgs[2]);
        self::assertSame('smy_i_publisher', $client->createArgs[3]);
        self::assertTrue($client->createArgs[7]);
        self::assertCount(8, $client->createArgs, 'URL Input must not be supplied.');
        self::assertSame('INGRESS_TEST', $result['ingress_id']);
        self::assertSame('https://whip.invalid/base/SAFE_TEST_KEY', $result['whip_endpoint']);
    }

    public function testDeleteUsesOnlyIngressId(): void
    {
        $client = new RecordingIngressClient();
        $gateway = new SdkLiveKitIngressGateway($this->config(), $client);
        $gateway->deleteIngress('INGRESS_TEST');
        self::assertSame(['INGRESS_TEST'], $client->deleted);
    }

    public function testListingReturnsOnlyStrictlyOwnedWhipIngressIds(): void
    {
        $client = new RecordingIngressClient();
        $client->listed = [
            $this->ingress('MATCH', 'expected-name', 'expected-room', 'expected-publisher'),
            $this->ingress('OTHER_NAME', 'other-name', 'expected-room', 'expected-publisher'),
            $this->ingress('OTHER_ROOM', 'expected-name', 'other-room', 'expected-publisher'),
            $this->ingress('OTHER_PUBLISHER', 'expected-name', 'expected-room', 'other-publisher'),
            $this->ingress('OTHER_INPUT', 'expected-name', 'expected-room', 'expected-publisher', IngressInput::RTMP_INPUT),
        ];
        $gateway = new SdkLiveKitIngressGateway($this->config(), $client);

        $result = $gateway->findOwnedIngressIds('expected-room', 'expected-name', 'expected-publisher');

        self::assertSame(['MATCH'], $result);
        self::assertSame([['expected-room', '']], $client->listArgs);
        self::assertSame(['MATCH'], array_values($result));
        self::assertStringNotContainsString('SAFE_LIST_KEY', json_encode($result, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('whip.invalid', json_encode($result, JSON_THROW_ON_ERROR));
    }

    public function testStructuredNotFoundDeleteIsIdempotent(): void
    {
        $client = new RecordingIngressClient();
        $client->deleteError = TwirpError::newError(ErrorCode::NotFound, 'resource is already gone');
        $gateway = new SdkLiveKitIngressGateway($this->config(), $client);

        $gateway->deleteIngress('ALREADY_GONE');

        self::assertSame(['ALREADY_GONE'], $client->deleted);
    }

    private function ingress(
        string $id,
        string $name,
        string $room,
        string $publisher,
        int $input = IngressInput::WHIP_INPUT,
    ): IngressInfo {
        return new IngressInfo([
            'ingress_id' => $id,
            'name' => $name,
            'room_name' => $room,
            'participant_identity' => $publisher,
            'input_type' => $input,
            'url' => 'https://whip.invalid/list-sensitive',
            'stream_key' => 'SAFE_LIST_KEY',
        ]);
    }

    private function config(): array
    {
        return ['enabled' => true, 'url' => 'wss://unit-test.invalid', 'api_key' => 'TEST_KEY', 'api_secret' => 'TEST_SECRET'];
    }
}

final class RecordingIngressClient extends IngressServiceClient
{
    public array $createArgs = [];
    public array $deleted = [];
    public array $listed = [];
    public array $listArgs = [];
    public ?TwirpError $deleteError = null;
    public function __construct() {}
    public function createIngress(
        int $inputType,
        string $name = '',
        string $roomName = '',
        string $participantIdentity = '',
        string $participantName = '',
        ?\Livekit\IngressAudioOptions $audio = null,
        ?\Livekit\IngressVideoOptions $video = null,
        ?bool $bypassTranscoding = null,
        ?string $url = null,
    ): IngressInfo {
        $this->createArgs = func_get_args();
        return new IngressInfo([
            'ingress_id' => 'INGRESS_TEST',
            'input_type' => IngressInput::WHIP_INPUT,
            'url' => 'https://whip.invalid/base',
            'stream_key' => 'SAFE_TEST_KEY',
            'bypass_transcoding' => true,
        ]);
    }
    public function deleteIngress(string $ingressId): IngressInfo
    {
        $this->deleted[] = $ingressId;
        if ($this->deleteError !== null) {
            throw $this->deleteError;
        }
        return new IngressInfo(['ingress_id' => $ingressId]);
    }
    public function listIngress(string $roomName = '', string $ingressId = ''): ListIngressResponse
    {
        $this->listArgs[] = [$roomName, $ingressId];
        return new ListIngressResponse(['items' => $this->listed]);
    }
}
