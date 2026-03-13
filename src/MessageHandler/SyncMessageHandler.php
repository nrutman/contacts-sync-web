<?php

namespace App\MessageHandler;

use App\Entity\SyncList;
use App\Entity\SyncRun;
use App\Entity\User;
use App\Message\SyncMessage;
use App\Notification\SyncNotificationService;
use App\Sync\SyncService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
class SyncMessageHandler
{
    public function __construct(
        private readonly SyncService $syncService,
        private readonly EntityManagerInterface $entityManager,
        private readonly SyncNotificationService $notificationService,
    ) {
    }

    public function __invoke(SyncMessage $message): void
    {
        $syncList = $this->entityManager
            ->getRepository(SyncList::class)
            ->find($message->syncListId);

        if ($syncList === null) {
            return; // List was deleted between dispatch and processing
        }

        $user = $message->triggeredByUserId !== null
            ? $this->entityManager->getRepository(User::class)->find($message->triggeredByUserId)
            : null;

        $existingSyncRun = $message->syncRunId !== null
            ? $this->entityManager->getRepository(SyncRun::class)->find($message->syncRunId)
            : null;

        $result = $this->syncService->executeSync(
            $syncList,
            $message->dryRun,
            $user,
            $message->trigger,
            $existingSyncRun,
        );

        if ($result->syncRun !== null) {
            $this->notificationService->sendBatchNotification([$result->syncRun]);
        }
    }
}
