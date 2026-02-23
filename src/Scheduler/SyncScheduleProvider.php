<?php

namespace App\Scheduler;

use App\Entity\SyncList;
use App\Message\SyncMessage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Scheduler\Attribute\AsSchedule;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;
use Symfony\Contracts\Cache\CacheInterface;

#[AsSchedule('sync')]
class SyncScheduleProvider implements ScheduleProviderInterface
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly CacheInterface $cache,
    ) {
    }

    public function getSchedule(): Schedule
    {
        $schedule = (new Schedule())
            ->stateful($this->cache)
            ->processOnlyLastMissedRun(true);

        $syncLists = $this->entityManager
            ->getRepository(SyncList::class)
            ->findBy(['isEnabled' => true]);

        foreach ($syncLists as $syncList) {
            if ($syncList->getCronExpression() === null || $syncList->getCronExpression() === '') {
                continue;
            }

            $schedule->add(
                RecurringMessage::cron(
                    $syncList->getCronExpression(),
                    new SyncMessage(
                        syncListId: (string) $syncList->getId(),
                        trigger: 'schedule',
                    ),
                ),
            );
        }

        return $schedule;
    }
}
