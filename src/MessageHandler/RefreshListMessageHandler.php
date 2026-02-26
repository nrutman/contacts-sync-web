<?php

namespace App\MessageHandler;

use App\Client\PlanningCenter\PlanningCenterProvider;
use App\Client\Provider\ProviderRegistry;
use App\Entity\SyncList;
use App\Message\RefreshListMessage;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
class RefreshListMessageHandler
{
    public function __construct(
        private readonly ProviderRegistry $providerRegistry,
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

        $sourceCredential = $syncList->getSourceCredential();

        if ($sourceCredential === null) {
            $this->logger->warning('Sync list "{name}" has no source credential configured.', [
                'name' => $syncList->getName(),
                'sync_list_id' => $message->syncListId,
            ]);

            return;
        }

        try {
            $provider = $this->providerRegistry->get($sourceCredential->getProviderName());

            if ($provider instanceof PlanningCenterProvider) {
                $provider->refreshList($sourceCredential, $syncList->getSourceListIdentifier() ?? $syncList->getName());
            }

            $this->logger->info('Source list "{name}" refreshed successfully.', [
                'name' => $syncList->getName(),
                'sync_list_id' => $message->syncListId,
                'triggered_by_user_id' => $message->triggeredByUserId,
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to refresh source list "{name}": {error}', [
                'name' => $syncList->getName(),
                'sync_list_id' => $message->syncListId,
                'error' => $e->getMessage(),
                'exception' => $e,
            ]);

            throw $e;
        }
    }
}
