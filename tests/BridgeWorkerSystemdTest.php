<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class BridgeWorkerSystemdTest extends TestCase
{
    private string $unit;

    protected function setUp(): void
    {
        $this->unit = (string) file_get_contents(
            dirname(__DIR__) . '/deploy/bridge-worker/systemd/semyra-bridge.service',
        );
    }

    public function testUnitDefinesDedicatedHardenedService(): void
    {
        foreach ([
            'User=semyra-bridge',
            'Group=semyra-bridge',
            'SupplementaryGroups=docker',
            'EnvironmentFile=/etc/semyra/bridge-worker.env',
            'RuntimeDirectory=semyra-bridge',
            'RuntimeDirectoryMode=0700',
            'WorkingDirectory=/opt/semyra-bridge/current',
            'After=network-online.target docker.service',
            'Requires=docker.service',
            'StartLimitIntervalSec=60',
            'StartLimitBurst=5',
            'ExecStartPre=/usr/bin/php /opt/semyra-bridge/current/worker.php --check',
            'ExecStart=/usr/bin/php /opt/semyra-bridge/current/worker.php --loop',
            'Restart=on-failure',
            'KillSignal=SIGTERM',
            'KillMode=mixed',
            'TimeoutStopSec=20s',
            'UMask=0077',
            'ReadWritePaths=/run/semyra-bridge',
            'NoNewPrivileges=true',
            'PrivateTmp=true',
            'ProtectSystem=strict',
            'ProtectHome=true',
            'ProtectKernelTunables=true',
            'ProtectKernelModules=true',
            'ProtectControlGroups=true',
            'RestrictSUIDSGID=true',
            'LockPersonality=true',
            'RestrictAddressFamilies=AF_UNIX AF_INET AF_INET6',
        ] as $directive) {
            self::assertStringContainsString($directive, $this->unit);
        }
    }

    public function testUnitAvoidsUnsafeShutdownAndCredentialPatterns(): void
    {
        foreach ([
            'Restart=always',
            'KillMode=control-group',
            'KillMode=process',
            'ExecStop=',
            'chmod 666',
            'docker kill',
            'SEMYRA_WORKER_SECRET=',
            'WHIP_ENDPOINT=',
            'provider',
        ] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $this->unit);
        }
    }
}
