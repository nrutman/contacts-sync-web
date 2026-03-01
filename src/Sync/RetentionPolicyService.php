<?php

namespace App\Sync;

use App\Event\SyncCompletedEvent;
use App\Repository\SyncRunRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

#[AsEventListener(event: SyncCompletedEvent::class)]
class RetentionPolicyService
{
    public function __construct(
        private readonly SyncRunRepository $syncRunRepository,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(SyncCompletedEvent $event): void
    {
        $syncRun = $event->syncRun;
        $organization = $syncRun->getSyncList()->getOrganization();
        $retentionDays = $organization->getRetentionDays();

        if ($retentionDays === null) {
            return;
        }

        $cutoff = new \DateTimeImmutable("today midnight -{$retentionDays} days");
        $deletedCount = $this->syncRunRepository->deleteOlderThan($organization, $cutoff);

        $this->logger->info('Retention policy: deleted {count} sync runs older than {days} days for organization "{org}"', [
            'count' => $deletedCount,
            'days' => $retentionDays,
            'org' => $organization->getName(),
        ]);
    }
}
