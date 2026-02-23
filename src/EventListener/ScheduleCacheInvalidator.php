<?php

namespace App\EventListener;

use App\Entity\SyncList;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Event\PostRemoveEventArgs;
use Doctrine\ORM\Event\PostUpdateEventArgs;
use Doctrine\ORM\Events;
use Symfony\Contracts\Cache\CacheInterface;

#[AsDoctrineListener(event: Events::postPersist)]
#[AsDoctrineListener(event: Events::postUpdate)]
#[AsDoctrineListener(event: Events::postRemove)]
class ScheduleCacheInvalidator
{
    public function __construct(
        private readonly CacheInterface $cache,
    ) {
    }

    public function postPersist(PostPersistEventArgs $args): void
    {
        $this->clearCacheIfSyncList($args->getObject());
    }

    public function postUpdate(PostUpdateEventArgs $args): void
    {
        $this->clearCacheIfSyncList($args->getObject());
    }

    public function postRemove(PostRemoveEventArgs $args): void
    {
        $this->clearCacheIfSyncList($args->getObject());
    }

    private function clearCacheIfSyncList(object $entity): void
    {
        if ($entity instanceof SyncList) {
            $this->cache->clear();
        }
    }
}
