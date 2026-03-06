<?php

namespace App\Tests\MessageHandler;

use App\Entity\SyncList;
use App\Entity\SyncRun;
use App\Entity\User;
use App\Message\SyncMessage;
use App\MessageHandler\SyncMessageHandler;
use App\Sync\SyncResult;
use App\Sync\SyncService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Mockery\Adapter\Phpunit\MockeryTestCase;
use Mockery as m;

class SyncMessageHandlerTest extends MockeryTestCase
{
    private SyncService|m\LegacyMockInterface $syncService;
    private EntityManagerInterface|m\LegacyMockInterface $entityManager;
    private EntityRepository|m\LegacyMockInterface $syncListRepository;
    private EntityRepository|m\LegacyMockInterface $userRepository;
    private EntityRepository|m\LegacyMockInterface $syncRunRepository;
    private SyncMessageHandler $handler;

    protected function setUp(): void
    {
        $this->syncService = m::mock(SyncService::class);
        $this->entityManager = m::mock(EntityManagerInterface::class);
        $this->syncListRepository = m::mock(EntityRepository::class);
        $this->userRepository = m::mock(EntityRepository::class);
        $this->syncRunRepository = m::mock(EntityRepository::class);

        $this->entityManager
            ->shouldReceive('getRepository')
            ->with(SyncList::class)
            ->andReturn($this->syncListRepository);

        $this->entityManager
            ->shouldReceive('getRepository')
            ->with(User::class)
            ->andReturn($this->userRepository);

        $this->entityManager
            ->shouldReceive('getRepository')
            ->with(SyncRun::class)
            ->andReturn($this->syncRunRepository);

        $this->handler = new SyncMessageHandler(
            $this->syncService,
            $this->entityManager,
        );
    }

    public function testInvokeExecutesSyncWithAllParameters(): void
    {
        $syncList = new SyncList();
        $syncList->setName('test-list');

        $user = new User();
        $user->setEmail('admin@test.com');
        $user->setFirstName('Admin');
        $user->setLastName('User');

        $syncRun = new SyncRun();

        $this->syncListRepository
            ->shouldReceive('find')
            ->with('list-123')
            ->once()
            ->andReturn($syncList);

        $this->userRepository
            ->shouldReceive('find')
            ->with('user-456')
            ->once()
            ->andReturn($user);

        $this->syncRunRepository
            ->shouldReceive('find')
            ->with('run-789')
            ->once()
            ->andReturn($syncRun);

        $this->syncService
            ->shouldReceive('executeSync')
            ->once()
            ->with($syncList, true, $user, 'manual', $syncRun)
            ->andReturn(new SyncResult(
                sourceCount: 5,
                destinationCount: 3,
                addedCount: 2,
                removedCount: 0,
                log: 'test log',
                success: true,
            ));

        $message = new SyncMessage(
            syncListId: 'list-123',
            dryRun: true,
            triggeredByUserId: 'user-456',
            trigger: 'manual',
            syncRunId: 'run-789',
        );

        ($this->handler)($message);
    }

    public function testInvokeReturnsEarlyWhenSyncListNotFound(): void
    {
        $this->syncListRepository
            ->shouldReceive('find')
            ->with('nonexistent')
            ->once()
            ->andReturnNull();

        $this->syncService->shouldNotReceive('executeSync');

        $message = new SyncMessage(syncListId: 'nonexistent');

        ($this->handler)($message);
    }

    public function testInvokeWithNoTriggeredByUser(): void
    {
        $syncList = new SyncList();
        $syncList->setName('test-list');

        $this->syncListRepository
            ->shouldReceive('find')
            ->with('list-123')
            ->once()
            ->andReturn($syncList);

        $this->syncService
            ->shouldReceive('executeSync')
            ->once()
            ->with($syncList, false, null, 'schedule', null)
            ->andReturn(new SyncResult(
                sourceCount: 0,
                destinationCount: 0,
                addedCount: 0,
                removedCount: 0,
                log: '',
                success: true,
            ));

        $message = new SyncMessage(
            syncListId: 'list-123',
            trigger: 'schedule',
        );

        ($this->handler)($message);
    }

    public function testInvokeWithNoExistingSyncRun(): void
    {
        $syncList = new SyncList();
        $syncList->setName('test-list');

        $user = new User();
        $user->setEmail('admin@test.com');
        $user->setFirstName('Admin');
        $user->setLastName('User');

        $this->syncListRepository
            ->shouldReceive('find')
            ->with('list-123')
            ->once()
            ->andReturn($syncList);

        $this->userRepository
            ->shouldReceive('find')
            ->with('user-456')
            ->once()
            ->andReturn($user);

        $this->syncService
            ->shouldReceive('executeSync')
            ->once()
            ->with($syncList, false, $user, 'manual', null)
            ->andReturn(new SyncResult(
                sourceCount: 0,
                destinationCount: 0,
                addedCount: 0,
                removedCount: 0,
                log: '',
                success: true,
            ));

        $message = new SyncMessage(
            syncListId: 'list-123',
            triggeredByUserId: 'user-456',
            trigger: 'manual',
        );

        ($this->handler)($message);
    }

    public function testInvokeWhenUserNotFoundPassesNull(): void
    {
        $syncList = new SyncList();
        $syncList->setName('test-list');

        $this->syncListRepository
            ->shouldReceive('find')
            ->with('list-123')
            ->once()
            ->andReturn($syncList);

        $this->userRepository
            ->shouldReceive('find')
            ->with('deleted-user')
            ->once()
            ->andReturnNull();

        $this->syncService
            ->shouldReceive('executeSync')
            ->once()
            ->with($syncList, false, null, 'manual', null)
            ->andReturn(new SyncResult(
                sourceCount: 0,
                destinationCount: 0,
                addedCount: 0,
                removedCount: 0,
                log: '',
                success: true,
            ));

        $message = new SyncMessage(
            syncListId: 'list-123',
            triggeredByUserId: 'deleted-user',
            trigger: 'manual',
        );

        ($this->handler)($message);
    }

    public function testInvokeWhenSyncRunNotFoundPassesNull(): void
    {
        $syncList = new SyncList();
        $syncList->setName('test-list');

        $this->syncListRepository
            ->shouldReceive('find')
            ->with('list-123')
            ->once()
            ->andReturn($syncList);

        $this->syncRunRepository
            ->shouldReceive('find')
            ->with('deleted-run')
            ->once()
            ->andReturnNull();

        $this->syncService
            ->shouldReceive('executeSync')
            ->once()
            ->with($syncList, false, null, 'manual', null)
            ->andReturn(new SyncResult(
                sourceCount: 0,
                destinationCount: 0,
                addedCount: 0,
                removedCount: 0,
                log: '',
                success: true,
            ));

        $message = new SyncMessage(
            syncListId: 'list-123',
            trigger: 'manual',
            syncRunId: 'deleted-run',
        );

        ($this->handler)($message);
    }
}
