<?php

declare(strict_types=1);

namespace Tests;

use Agence104\LiveKit\IngressServiceClient;
use App\Services\SdkLiveKitIngressGateway;
use Livekit\IngressInfo;
use Livekit\IngressInput;
use Livekit\IngressState;
use Livekit\IngressState\Status;
use Livekit\ListIngressResponse;
use Livekit\TrackSource;
use Livekit\TwirpError;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Twirp\ErrorCode;

final class SdkLiveKitIngressGatewayTest extends TestCase
{
    public function testConvertsWebsocketUrlAndCreatesWhipWithoutTranscoding(): void
    {
        $client = new RecordingIngressClient();
        $gateway = new SdkLiveKitIngressGateway($this->config(), $client);
        $result = $gateway->createWhipIngress('smy_b_' . str_repeat('a', 32) . '_a1', 'smy_r_room', 'smy_i_publisher');

        self::assertSame('https://unit-test.invalid', $gateway->apiUrl());
        self::assertSame(IngressInput::WHIP_INPUT, $client->createArgs[0]);
        self::assertSame('smy_r_room', $client->createArgs[2]);
        self::assertSame('smy_i_publisher', $client->createArgs[3]);
        self::assertNull($client->createArgs[5]);
        self::assertNull($client->createArgs[6]);
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

    public function testScreenWhipIngressDeclaresScreenShareTrackSources(): void
    {
        $client = new RecordingIngressClient();
        $gateway = new SdkLiveKitIngressGateway($this->config(), $client);

        $gateway->createWhipIngress('screen', 'room', 'publisher', true);

        self::assertSame(TrackSource::SCREEN_SHARE_AUDIO, $client->createArgs[5]->getSource());
        self::assertSame(TrackSource::SCREEN_SHARE, $client->createArgs[6]->getSource());
        self::assertTrue($client->createArgs[7]);
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

    public function testStatusRequiresExactOwnedIngressAndReturnsOnlySanitizedState(): void
    {
        $client = new RecordingIngressClient();
        $matching = $this->ingress('READY', 'expected-name', 'expected-room', 'expected-publisher');
        $matching->setState(new IngressState(['status' => Status::ENDPOINT_PUBLISHING, 'error' => 'sensitive upstream detail']));
        $client->listed = [$matching, $this->ingress('FOREIGN', 'other-name', 'expected-room', 'expected-publisher')];
        $gateway = new SdkLiveKitIngressGateway($this->config(), $client);

        self::assertSame('publishing', $gateway->findIngressStatus('expected-room', 'expected-name', 'expected-publisher', 'READY'));
        self::assertNull($gateway->findIngressStatus('expected-room', 'expected-name', 'expected-publisher', 'FOREIGN'));
        self::assertSame([['', 'READY'], ['', 'FOREIGN']], $client->listArgs);
    }

    #[DataProvider('ingressStates')]
    public function testStatusMapsEverySdkIngressState(int $sdkStatus, string $expected): void
    {
        $client = new RecordingIngressClient();
        $matching = $this->ingress('STATE', 'expected-name', 'expected-room', 'expected-publisher');
        $matching->setState(new IngressState(['status' => $sdkStatus, 'error' => 'sensitive upstream detail']));
        $client->listed = [$matching];
        $gateway = new SdkLiveKitIngressGateway($this->config(), $client);

        self::assertSame($expected, $gateway->findIngressStatus(
            'expected-room', 'expected-name', 'expected-publisher', 'STATE'));
    }

    public static function ingressStates(): array
    {
        return [
            'inactive' => [Status::ENDPOINT_INACTIVE, 'inactive'],
            'buffering' => [Status::ENDPOINT_BUFFERING, 'buffering'],
            'publishing' => [Status::ENDPOINT_PUBLISHING, 'publishing'],
            'error' => [Status::ENDPOINT_ERROR, 'error'],
            'complete' => [Status::ENDPOINT_COMPLETE, 'complete'],
        ];
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
