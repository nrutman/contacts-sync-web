<?php

namespace App\MessageHandler;

use App\Client\PlanningCenter\PlanningCenterClientFactory;
use App\Entity\SyncList;
use App\Message\RefreshListMessage;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
class RefreshListMessageHandler
{
    public function __construct(
        private readonly PlanningCenterClientFactory $planningCenterClientFactory,
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(RefreshListMessage $message): void
    {
        $syncList = $this->entityManager
            ->getRepository(SyncList::class)
            ->find($message->syncListId);

        if ($syncList === null) {
            return; // List was deleted between dispatch and processing
        }

        $organization = $syncList->getOrganization();

        try {
            $planningCenterClient = $this->planningCenterClientFactory->create($organization);
            $planningCenterClient->refreshList($syncList->getName());

            $this->logger->info('Planning Center list "{name}" refreshed successfully.', [
                'name' => $syncList->getName(),
                'sync_list_id' => $message->syncListId,
                'triggered_by_user_id' => $message->triggeredByUserId,
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to refresh Planning Center list "{name}": {error}', [
                'name' => $syncList->getName(),
                'sync_list_id' => $message->syncListId,
                'error' => $e->getMessage(),
                'exception' => $e,
            ]);

            throw $e;
        }
    }
}
