<?php

namespace App\Tests\Sync;

use App\Entity\Organization;
use App\Entity\SyncList;
use App\Entity\SyncRun;
use App\Event\SyncCompletedEvent;
use App\Repository\SyncRunRepository;
use App\Sync\RetentionPolicyService;
use Mockery\Adapter\Phpunit\MockeryTestCase;
use Mockery as m;
use Psr\Log\LoggerInterface;

class RetentionPolicyServiceTest extends MockeryTestCase
{
    /** @var SyncRunRepository|m\LegacyMockInterface|m\MockInterface */
    private $syncRunRepository;

    /** @var LoggerInterface|m\LegacyMockInterface|m\MockInterface */
    private $logger;

    private RetentionPolicyService $service;

    protected function setUp(): void
    {
        $this->syncRunRepository = m::mock(SyncRunRepository::class);
        $this->logger = m::mock(LoggerInterface::class)->shouldIgnoreMissing();

        $this->service = new RetentionPolicyService(
            $this->syncRunRepository,
            $this->logger,
        );
    }

    public function testSkipsCleanupWhenRetentionDaysIsNull(): void
    {
        $organization = new Organization();
        $organization->setName('Test Org');

        $syncRun = $this->makeSyncRun($organization);

        $this->syncRunRepository->shouldNotReceive('deleteOlderThan');

        $this->service->__invoke(new SyncCompletedEvent($syncRun));
    }

    public function testDeletesOldRunsWhenRetentionDaysIsSet(): void
    {
        $organization = new Organization();
        $organization->setName('Test Org');
        $organization->setRetentionDays(30);

        $syncRun = $this->makeSyncRun($organization);

        $this->syncRunRepository
            ->shouldReceive('deleteOlderThan')
            ->once()
            ->with($organization, m::on(function (\DateTimeImmutable $cutoff) {
                $expected = new \DateTimeImmutable('today midnight -30 days');

                return $cutoff->format('Y-m-d H:i:s') === $expected->format('Y-m-d H:i:s');
            }))
            ->andReturn(7);

        $this->logger
            ->shouldReceive('info')
            ->once()
            ->with(m::on(fn (string $msg) => str_contains($msg, 'Retention policy')), m::on(function (array $context) {
                return $context['count'] === 7
                    && $context['days'] === 30
                    && $context['org'] === 'Test Org';
            }));

        $this->service->__invoke(new SyncCompletedEvent($syncRun));
    }

    public function testLogsZeroDeletedCount(): void
    {
        $organization = new Organization();
        $organization->setName('Clean Org');
        $organization->setRetentionDays(365);

        $syncRun = $this->makeSyncRun($organization);

        $this->syncRunRepository
            ->shouldReceive('deleteOlderThan')
            ->once()
            ->andReturn(0);

        $this->logger
            ->shouldReceive('info')
            ->once()
            ->with(m::any(), m::on(fn (array $ctx) => $ctx['count'] === 0 && $ctx['days'] === 365));

        $this->service->__invoke(new SyncCompletedEvent($syncRun));
    }

    private function makeSyncRun(Organization $organization): SyncRun
    {
        $syncList = new SyncList();
        $syncList->setName('test-list');
        $organization->addSyncList($syncList);

        $syncRun = new SyncRun();
        $syncRun->setSyncList($syncList);
        $syncRun->setTriggeredBy('manual');
        $syncRun->setStatus('success');

        return $syncRun;
    }
}
