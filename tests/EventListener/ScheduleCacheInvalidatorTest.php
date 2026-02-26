<?php

namespace App\Tests\EventListener;

use App\Entity\Organization;
use App\Entity\SyncList;
use App\Entity\User;
use App\EventListener\ScheduleCacheInvalidator;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Event\PostRemoveEventArgs;
use Doctrine\ORM\Event\PostUpdateEventArgs;
use Mockery\Adapter\Phpunit\MockeryTestCase;
use Mockery as m;
use Symfony\Contracts\Cache\CacheInterface;

class ScheduleCacheInvalidatorTest extends MockeryTestCase
{
    private CacheInterface|m\LegacyMockInterface $cache;
    private EntityManagerInterface|m\LegacyMockInterface $entityManager;
    private ScheduleCacheInvalidator $listener;

    protected function setUp(): void
    {
        $this->cache = m::mock(CacheInterface::class);
        $this->entityManager = m::mock(EntityManagerInterface::class);

        $this->listener = new ScheduleCacheInvalidator($this->cache);
    }

    public function testPostPersistClearsCacheForSyncList(): void
    {
        $syncList = new SyncList();
        $syncList->setName('Test List');

        $args = new PostPersistEventArgs($syncList, $this->entityManager);

        $this->cache->shouldReceive('clear')->once();

        $this->listener->postPersist($args);
    }

    public function testPostUpdateClearsCacheForSyncList(): void
    {
        $syncList = new SyncList();
        $syncList->setName('Test List');

        $args = new PostUpdateEventArgs($syncList, $this->entityManager);

        $this->cache->shouldReceive('clear')->once();

        $this->listener->postUpdate($args);
    }

    public function testPostRemoveClearsCacheForSyncList(): void
    {
        $syncList = new SyncList();
        $syncList->setName('Test List');

        $args = new PostRemoveEventArgs($syncList, $this->entityManager);

        $this->cache->shouldReceive('clear')->once();

        $this->listener->postRemove($args);
    }

    public function testPostPersistDoesNotClearCacheForOtherEntities(): void
    {
        $user = new User();
        $user->setEmail('test@example.com');
        $user->setFirstName('Test');
        $user->setLastName('User');

        $args = new PostPersistEventArgs($user, $this->entityManager);

        $this->cache->shouldNotReceive('clear');

        $this->listener->postPersist($args);
    }

    public function testPostUpdateDoesNotClearCacheForOtherEntities(): void
    {
        $organization = new Organization();
        $organization->setName('Test Org');

        $args = new PostUpdateEventArgs($organization, $this->entityManager);

        $this->cache->shouldNotReceive('clear');

        $this->listener->postUpdate($args);
    }

    public function testPostRemoveDoesNotClearCacheForOtherEntities(): void
    {
        $user = new User();
        $user->setEmail('test@example.com');
        $user->setFirstName('Test');
        $user->setLastName('User');

        $args = new PostRemoveEventArgs($user, $this->entityManager);

        $this->cache->shouldNotReceive('clear');

        $this->listener->postRemove($args);
    }
}
