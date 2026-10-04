<?php

declare(strict_types=1);

namespace Tests;

use App\Controllers\MediaBridgeController;
use App\Core\Request;
use App\Services\LiveKitIngressGateway;
use App\Services\LiveKitRoomContext;
use App\Services\MediaBridgeCoordinator;
use App\Services\MediaBridgeJobStore;
use App\Services\MediaBridgeWorkerAuthenticator;
use PHPUnit\Framework\TestCase;

final class MediaBridgeControllerTest extends TestCase
{
    private const SECRET='aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const WORKER='wrk_bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
    private const INSTANCE='cccccccccccccccccccccccccccccccc';

    public function testUnauthorizedResponsesDoNotRevealAuthenticationReason(): void
    {
        foreach ([null, 'invalid', str_repeat('d',64)] as $secret) {
            $response=$this->controller(['worker_id'=>self::WORKER],$secret)->claim();
            self::assertSame(401,$response->status());
            self::assertSame(['error'=>'worker_unauthorized'],json_decode($response->body(),true));
        }
    }

    public function testDisabledBridgeProducesNoJob(): void
    {
        $response=$this->controller(['worker_id'=>self::WORKER],self::SECRET,false)->claim();
        self::assertSame(401,$response->status());
        self::assertSame('{"error":"worker_unauthorized"}',$response->body());
    }

    public function testNoJobReturns204WithSafeHeaders(): void
    {
        $store=new ControllerBridgeStore(self::INSTANCE);
        $store->claimable=false;
        $response=$this->controller(['worker_id'=>self::WORKER],self::SECRET,true,$store)->claim();
        self::assertSame(204,$response->status());
        self::assertSame('',$response->body());
        self::assertSame('no-store',$response->headers()['Cache-Control']);
        self::assertSame('nosniff',$response->headers()['X-Content-Type-Options']);
    }

    public function testClaimResponseContainsOnlyWorkerFieldsAndWhipCredential(): void
    {
        $response=$this->controller(['worker_id'=>self::WORKER],self::SECRET)->claim();
        $body=json_decode($response->body(),true,flags:JSON_THROW_ON_ERROR);
        self::assertSame(201,$response->status());
        self::assertSame(['job_id','transmission_instance_id','source_ref','lease_token','lease_seconds','whip_endpoint'],array_keys($body));
        self::assertArrayNotHasKey('room_code',$body);
        self::assertArrayNotHasKey('api_key',$body);
        self::assertArrayNotHasKey('api_secret',$body);
        self::assertArrayNotHasKey('provider_url',$body);
    }

    public function testHeartbeatAndReportDoNotUseCsrfOrUserSession(): void
    {
        $store=new ControllerBridgeStore(self::INSTANCE);
        $claimResponse=$this->controller(['worker_id'=>self::WORKER],self::SECRET,true,$store)->claim();
        $claim=json_decode($claimResponse->body(),true,flags:JSON_THROW_ON_ERROR);
        $common=['worker_id'=>self::WORKER,'job_id'=>'7','transmission_instance_id'=>self::INSTANCE,'lease_token'=>$claim['lease_token']];
        $heartbeat=$this->controller($common+['status'=>'running'],self::SECRET,true,$store)->heartbeat();
        self::assertSame(['action'=>'keep','lease_seconds'=>20],json_decode($heartbeat->body(),true));
        $report=$this->controller($common+['status'=>'stopped'],self::SECRET,true,$store)->report();
        self::assertSame(['action'=>'stopped'],json_decode($report->body(),true));
    }

    private function controller(array $body,?string $secret,bool $enabled=true,?ControllerBridgeStore $store=null): MediaBridgeController
    {
        $server=['HTTP_HOST'=>'127.0.0.1:8010']; if($secret!==null)$server['HTTP_X_SEMYRA_WORKER_TOKEN']=$secret;
        $request=new Request(parsedBody:$body,server:$server);
        $store??=new ControllerBridgeStore(self::INSTANCE);
        return new MediaBridgeController(
            $request,
            new MediaBridgeWorkerAuthenticator(['enabled'=>$enabled,'worker_secret'=>self::SECRET],'testing'),
            new MediaBridgeCoordinator($store,new ControllerIngressGateway(),new LiveKitRoomContext('testing'),20,3),
        );
    }
}

final class ControllerBridgeStore implements MediaBridgeJobStore
{
    public array $job; public bool $claimable=true;
    public function __construct(string $instance){$this->job=['id'=>7,'room_id'=>11,'transmission_instance_id'=>$instance,'source_ref'=>'test_source_01','desired_state'=>'running','status'=>'pending','worker_id'=>null,'lease_token_hash'=>null,'lease_active'=>1,'ingress_id'=>null,'attempt_count'=>0,'failure_count'=>0,'cleanup_through_attempt'=>0];}
    public function create(int $roomId,string $instanceId,string $sourceRef):int{return 7;}
    public function findByInstance(string $instanceId):?array{return $this->job;}
    public function requestStop(string $instanceId):bool{$this->job['desired_state']='stopped';return true;}
    public function reconcileStaleJobs():void{}
    public function staleJobsForCleanup():array{return [];}
    public function claim(string $workerId,string $leaseHash,int $leaseSeconds,int $maxFailures):?array{if(!$this->claimable||$this->job['failure_count']>=$maxFailures||$this->job['cleanup_through_attempt']!==$this->job['attempt_count'])return null;$this->job['worker_id']=$workerId;$this->job['lease_token_hash']=$leaseHash;$this->job['status']='claimed';$this->job['attempt_count']++;return $this->job;}
    public function currentTransmissionIsEligible(array $job):bool{return true;}
    public function findLeaseContext(int $jobId,string $instanceId,string $workerId):?array{return $this->job['worker_id']===$workerId?array_merge($this->job,['lease_active'=>1]):null;}
    public function renewLease(int $jobId,string $instanceId,string $workerId,string $leaseHash,string $status,int $leaseSeconds):bool{$this->job['status']=$status;return $this->match($workerId,$leaseHash);}
    public function markStopping(int $jobId,string $instanceId,string $workerId,string $leaseHash):bool{$this->job['desired_state']='stopped';return $this->match($workerId,$leaseHash);}
    public function markRunning(int $jobId,string $instanceId,string $workerId,string $leaseHash):bool{$this->job['status']='running';return $this->match($workerId,$leaseHash);}
    public function markFailed(int $jobId,string $instanceId,string $workerId,string $leaseHash,string $errorCode,bool $keepIngress,bool $consumeFailureBudget):bool{return $this->match($workerId,$leaseHash);}
    public function markStopped(int $jobId,string $instanceId,string $workerId,string $leaseHash,bool $keepIngress):bool{if(!$this->match($workerId,$leaseHash))return false;$this->job['status']='stopped';$this->job['desired_state']='stopped';return true;}
    public function setIngress(int $jobId,string $instanceId,string $workerId,string $leaseHash,string $ingressId):bool{if(!$this->match($workerId,$leaseHash))return false;$this->job['ingress_id']=$ingressId;return true;}
    public function clearIngress(int $jobId,string $instanceId,string $workerId,string $leaseHash,string $ingressId):bool{$this->job['ingress_id']=null;return $this->match($workerId,$leaseHash);}
    public function advanceCleanupThrough(int $jobId,string $instanceId,string $workerId,string $leaseHash,int $throughAttempt):bool{$this->job['cleanup_through_attempt']=$throughAttempt;return $this->match($workerId,$leaseHash);}
    public function completeReconciledCleanup(int $jobId,int $throughAttempt):bool{return true;}
    public function recordCleanupPending(int $jobId,int $expectedAttempt):bool{return $this->job['attempt_count']===$expectedAttempt;}
    private function match(string $worker,string $hash):bool{return $this->job['worker_id']===$worker&&$this->job['lease_token_hash']===$hash;}
}

final class ControllerIngressGateway implements LiveKitIngressGateway
{
    public function createWhipIngress(string $name,string $roomName,string $publisherIdentity):array{return ['ingress_id'=>'INGRESS_TEST','whip_endpoint'=>'https://whip.invalid/SAFE_TEST_CREDENTIAL'];}
    public function findOwnedIngressIds(string $roomName,string $ingressName,string $publisherIdentity):array{return [];}
    public function deleteIngress(string $ingressId):void{}
}
