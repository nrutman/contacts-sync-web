<?php

namespace App\Tests\Command;

use App\Command\RunSyncCommand;
use App\Entity\Organization;
use App\Entity\SyncList;
use App\Entity\SyncRun;
use App\Notification\SyncNotificationService;
use App\Repository\SyncRunRepository;
use App\Sync\SyncResult;
use App\Sync\SyncService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Mockery\Adapter\Phpunit\MockeryTestCase;
use Mockery as m;
use Symfony\Component\Console\Tester\CommandTester;

class RunSyncCommandTest extends MockeryTestCase
{
    private const LIST_ONE = 'list1@domain.com';
    private const LIST_TWO = 'list2@domain.com';

    /** @var SyncService|m\LegacyMockInterface|m\MockInterface */
    private $syncService;

    /** @var EntityManagerInterface|m\LegacyMockInterface|m\MockInterface */
    private $entityManager;

    /** @var EntityRepository|m\LegacyMockInterface|m\MockInterface */
    private $syncListRepository;

    /** @var SyncRunRepository|m\LegacyMockInterface|m\MockInterface */
    private $syncRunRepository;

    /** @var SyncNotificationService|m\LegacyMockInterface|m\MockInterface */
    private $notificationService;

    public function setUp(): void
    {
        $this->syncService = m::mock(SyncService::class);
        $this->entityManager = m::mock(EntityManagerInterface::class);
        $this->syncListRepository = m::mock(EntityRepository::class);
        $this->syncRunRepository = m::mock(SyncRunRepository::class);
        $this->notificationService = m::mock(SyncNotificationService::class);
        $this->notificationService->shouldReceive('sendBatchNotification')->byDefault();
        $this->notificationService->shouldReceive('getLastResults')->andReturn([])->byDefault();

        $this->entityManager
            ->shouldReceive('getRepository')
            ->with(SyncList::class)
            ->andReturn($this->syncListRepository);
    }

    public function testExecuteSuccessfulSync(): void
    {
        $syncList = $this->makeSyncList(self::LIST_ONE);

        $this->syncListRepository
            ->shouldReceive('findBy')
            ->with(['isEnabled' => true])
            ->andReturn([$syncList]);

        $this->syncService
            ->shouldReceive('executeSync')
            ->once()
            ->withArgs(function (SyncList $sl, bool $dryRun, ?object $user, string $trigger, ?object $syncRun, bool $skipRefresh) {
                return $sl->getName() === self::LIST_ONE
                    && $dryRun === false
                    && $user === null
                    && $trigger === 'cli'
                    && $skipRefresh === false;
            })
            ->andReturn(
                new SyncResult(
                    sourceCount: 5,
                    destinationCount: 3,
                    addedCount: 1,
                    removedCount: 1,
                    log: "Adding source@test.com\nRemoving old@test.com\n",
                    success: true,
                ),
            );

        $tester = $this->executeCommand();

        self::assertEquals(0, $tester->getStatusCode());
        self::assertStringContainsString(
            'source@test.com',
            $tester->getDisplay(),
        );
        self::assertStringContainsString('old@test.com', $tester->getDisplay());
    }

    public function testExecuteDryRun(): void
    {
        $syncList = $this->makeSyncList(self::LIST_ONE);

        $this->syncListRepository
            ->shouldReceive('findBy')
            ->with(['isEnabled' => true])
            ->andReturn([$syncList]);

        $this->syncService
            ->shouldReceive('executeSync')
            ->once()
            ->withArgs(function (SyncList $sl, bool $dryRun, ?object $user, string $trigger) {
                return $sl->getName() === self::LIST_ONE
                    && $dryRun === true
                    && $user === null
                    && $trigger === 'cli';
            })
            ->andReturn(
                new SyncResult(
                    sourceCount: 2,
                    destinationCount: 1,
                    addedCount: 1,
                    removedCount: 0,
                    log: "Dry run — no changes applied.\n",
                    success: true,
                ),
            );

        $tester = $this->executeCommand(['--dry-run' => true]);

        self::assertEquals(0, $tester->getStatusCode());
        self::assertStringContainsString('dry run', $tester->getDisplay());
    }

    public function testExecuteNoSyncListsFound(): void
    {
        $this->syncListRepository
            ->shouldReceive('findBy')
            ->with(['isEnabled' => true])
            ->andReturn([]);

        $tester = $this->executeCommand();

        self::assertEquals(1, $tester->getStatusCode());
        self::assertStringContainsString(
            'No sync lists found',
            $tester->getDisplay(),
        );
    }

    public function testExecuteMultipleLists(): void
    {
        $syncList1 = $this->makeSyncList(self::LIST_ONE);
        $syncList2 = $this->makeSyncList(self::LIST_TWO);

        $this->syncListRepository
            ->shouldReceive('findBy')
            ->with(['isEnabled' => true])
            ->andReturn([$syncList1, $syncList2]);

        $this->syncService
            ->shouldReceive('executeSync')
            ->twice()
            ->andReturn(
                new SyncResult(
                    sourceCount: 3,
                    destinationCount: 3,
                    addedCount: 0,
                    removedCount: 0,
                    log: '',
                    success: true,
                ),
            );

        $tester = $this->executeCommand();

        self::assertEquals(0, $tester->getStatusCode());
        self::assertStringContainsString('1/2', $tester->getDisplay());
        self::assertStringContainsString('2/2', $tester->getDisplay());
    }

    public function testExecuteWithListFilterOption(): void
    {
        $syncList = $this->makeSyncList(self::LIST_ONE);

        $this->syncListRepository
            ->shouldReceive('findBy')
            ->with(['name' => self::LIST_ONE])
            ->andReturn([$syncList]);

        $this->syncService
            ->shouldReceive('executeSync')
            ->once()
            ->andReturn(
                new SyncResult(
                    sourceCount: 1,
                    destinationCount: 1,
                    addedCount: 0,
                    removedCount: 0,
                    log: '',
                    success: true,
                ),
            );

        $tester = $this->executeCommand(['--list' => self::LIST_ONE]);

        self::assertEquals(0, $tester->getStatusCode());
    }

    public function testExecuteSyncFailure(): void
    {
        $syncList = $this->makeSyncList(self::LIST_ONE);

        $this->syncListRepository
            ->shouldReceive('findBy')
            ->with(['isEnabled' => true])
            ->andReturn([$syncList]);

        $this->syncService
            ->shouldReceive('executeSync')
            ->once()
            ->andReturn(
                new SyncResult(
                    sourceCount: 0,
                    destinationCount: 0,
                    addedCount: 0,
                    removedCount: 0,
                    log: "ERROR: Connection refused\n",
                    success: false,
                    errorMessage: 'Connection refused',
                ),
            );

        $tester = $this->executeCommand();

        self::assertEquals(1, $tester->getStatusCode());
        self::assertStringContainsString('Sync failed', $tester->getDisplay());
        self::assertStringContainsString(
            'Connection refused',
            $tester->getDisplay(),
        );
    }

    public function testExecutePartialFailure(): void
    {
        $syncList1 = $this->makeSyncList(self::LIST_ONE);
        $syncList2 = $this->makeSyncList(self::LIST_TWO);

        $this->syncListRepository
            ->shouldReceive('findBy')
            ->with(['isEnabled' => true])
            ->andReturn([$syncList1, $syncList2]);

        $this->syncService
            ->shouldReceive('executeSync')
            ->once()
            ->withArgs(function (SyncList $sl, bool $dryRun, ?object $user, string $trigger) {
                return $sl->getName() === self::LIST_ONE
                    && $dryRun === false
                    && $user === null
                    && $trigger === 'cli';
            })
            ->andReturn(
                new SyncResult(
                    sourceCount: 3,
                    destinationCount: 3,
                    addedCount: 0,
                    removedCount: 0,
                    log: '',
                    success: true,
                ),
            );

        $this->syncService
            ->shouldReceive('executeSync')
            ->once()
            ->withArgs(function (SyncList $sl, bool $dryRun, ?object $user, string $trigger) {
                return $sl->getName() === self::LIST_TWO
                    && $dryRun === false
                    && $user === null
                    && $trigger === 'cli';
            })
            ->andReturn(
                new SyncResult(
                    sourceCount: 0,
                    destinationCount: 0,
                    addedCount: 0,
                    removedCount: 0,
                    log: '',
                    success: false,
                    errorMessage: 'API error',
                ),
            );

        $tester = $this->executeCommand();

        self::assertEquals(1, $tester->getStatusCode());
        self::assertStringContainsString('Sync failed', $tester->getDisplay());
    }

    public function testExecuteDisplaysTableWithCounts(): void
    {
        $syncList = $this->makeSyncList(self::LIST_ONE);

        $this->syncListRepository
            ->shouldReceive('findBy')
            ->with(['isEnabled' => true])
            ->andReturn([$syncList]);

        $this->syncService
            ->shouldReceive('executeSync')
            ->once()
            ->andReturn(
                new SyncResult(
                    sourceCount: 10,
                    destinationCount: 8,
                    addedCount: 3,
                    removedCount: 1,
                    log: '',
                    success: true,
                ),
            );

        $tester = $this->executeCommand();

        $display = $tester->getDisplay();

        self::assertEquals(0, $tester->getStatusCode());
        self::assertStringContainsString('Source', $display);
        self::assertStringContainsString('Destination', $display);
        self::assertStringContainsString('To Remove', $display);
        self::assertStringContainsString('To Add', $display);
        self::assertStringContainsString('10', $display);
        self::assertStringContainsString('8', $display);
        self::assertStringContainsString('3', $display);
    }

    private function executeCommand(array $options = []): CommandTester
    {
        $command = new RunSyncCommand(
            $this->syncService,
            $this->entityManager,
            $this->syncRunRepository,
            $this->notificationService,
        );

        $tester = new CommandTester($command);
        $tester->execute($options);

        return $tester;
    }

    private function makeSyncList(
        string $name,
        ?string $cronExpression = null,
    ): SyncList {
        $organization = new Organization();
        $organization->setName('Test Org');

        $syncList = new SyncList();
        $syncList->setName($name);
        $syncList->setOrganization($organization);

        if ($cronExpression !== null) {
            $syncList->setCronExpression($cronExpression);
        }

        return $syncList;
    }

    private function makeSyncRun(
        SyncList $syncList,
        \DateTimeImmutable $createdAt,
    ): SyncRun {
        $syncRun = new SyncRun();
        $syncRun->setSyncList($syncList);
        $syncRun->setTriggeredBy('cli');

        $reflection = new \ReflectionProperty(SyncRun::class, 'createdAt');
        $reflection->setValue($syncRun, $createdAt);

        return $syncRun;
    }

    private function makeSuccessResult(): SyncResult
    {
        return new SyncResult(
            sourceCount: 3,
            destinationCount: 3,
            addedCount: 0,
            removedCount: 0,
            log: '',
            success: true,
        );
    }

    public function testDisplaysNotificationResults(): void
    {
        $syncList = $this->makeSyncList(self::LIST_ONE);
        $syncRun = $this->makeSyncRun($syncList, new \DateTimeImmutable());

        $this->syncListRepository
            ->shouldReceive('findBy')
            ->with(['isEnabled' => true])
            ->andReturn([$syncList]);

        $this->syncService
            ->shouldReceive('executeSync')
            ->once()
            ->andReturn(new SyncResult(
                sourceCount: 3,
                destinationCount: 3,
                addedCount: 0,
                removedCount: 0,
                log: '',
                success: true,
                syncRun: $syncRun,
            ));

        $this->notificationService
            ->shouldReceive('sendBatchNotification')
            ->once()
            ->with([$syncRun]);

        $this->notificationService
            ->shouldReceive('getLastResults')
            ->andReturn([
                ['email' => 'user@example.com', 'success' => true, 'error' => null],
                ['email' => 'admin@example.com', 'success' => false, 'error' => 'Connection timed out'],
            ]);

        $tester = $this->executeCommand();
        $display = $tester->getDisplay();

        self::assertStringContainsString('Notification sent to user@example.com', $display);
        self::assertStringContainsString('Notification to admin@example.com failed: Connection timed out', $display);
    }

    public function testExecuteWithNoRefreshFlag(): void
    {
        $syncList = $this->makeSyncList(self::LIST_ONE);

        $this->syncListRepository
            ->shouldReceive('findBy')
            ->with(['isEnabled' => true])
            ->andReturn([$syncList]);

        $this->syncService
            ->shouldReceive('executeSync')
            ->once()
            ->withArgs(function (SyncList $sl, bool $dryRun, ?object $user, string $trigger, ?object $syncRun, bool $skipRefresh) {
                return $sl->getName() === self::LIST_ONE
                    && $dryRun === false
                    && $user === null
                    && $trigger === 'cli'
                    && $skipRefresh === true;
            })
            ->andReturn(
                new SyncResult(
                    sourceCount: 3,
                    destinationCount: 3,
                    addedCount: 0,
                    removedCount: 0,
                    log: '',
                    success: true,
                ),
            );

        $tester = $this->executeCommand(['--no-refresh' => true]);

        self::assertEquals(0, $tester->getStatusCode());
    }

    public function testScheduledSkipsListsWithoutCronExpression(): void
    {
        $syncList = $this->makeSyncList(self::LIST_ONE);

        $this->syncListRepository
            ->shouldReceive('findBy')
            ->with(['isEnabled' => true])
            ->andReturn([$syncList]);

        $tester = $this->executeCommand(['--scheduled' => true]);

        self::assertEquals(0, $tester->getStatusCode());
        self::assertStringContainsString('no cron expression', $tester->getDisplay());
        self::assertStringContainsString('Nothing to do', $tester->getDisplay());
    }

    public function testScheduledRunsListsWithNoPriorRuns(): void
    {
        $syncList = $this->makeSyncList(self::LIST_ONE, '*/5 * * * *');

        $this->syncListRepository
            ->shouldReceive('findBy')
            ->with(['isEnabled' => true])
            ->andReturn([$syncList]);

        $this->syncRunRepository
            ->shouldReceive('findLastBySyncList')
            ->with($syncList)
            ->andReturnNull();

        $this->syncService
            ->shouldReceive('executeSync')
            ->once()
            ->withArgs(function (SyncList $sl, bool $dryRun, ?object $user, string $trigger) {
                return $sl->getName() === self::LIST_ONE
                    && $dryRun === false
                    && $user === null
                    && $trigger === 'schedule';
            })
            ->andReturn($this->makeSuccessResult());

        $tester = $this->executeCommand(['--scheduled' => true]);

        self::assertEquals(0, $tester->getStatusCode());
        self::assertStringContainsString('never synced', $tester->getDisplay());
    }

    public function testScheduledSkipsListsNotYetDue(): void
    {
        $syncList = $this->makeSyncList(self::LIST_ONE, '*/5 * * * *');

        $this->syncListRepository
            ->shouldReceive('findBy')
            ->with(['isEnabled' => true])
            ->andReturn([$syncList]);

        // Last run was just now — next run is 5 minutes from now, so not due
        $lastRun = $this->makeSyncRun($syncList, new \DateTimeImmutable());

        $this->syncRunRepository
            ->shouldReceive('findLastBySyncList')
            ->with($syncList)
            ->andReturn($lastRun);

        $tester = $this->executeCommand(['--scheduled' => true]);

        self::assertEquals(0, $tester->getStatusCode());
        self::assertStringContainsString('not due until', $tester->getDisplay());
        self::assertStringContainsString('Nothing to do', $tester->getDisplay());
    }

    public function testScheduledRunsListsThatAreDue(): void
    {
        $syncList = $this->makeSyncList(self::LIST_ONE, '*/5 * * * *');

        $this->syncListRepository
            ->shouldReceive('findBy')
            ->with(['isEnabled' => true])
            ->andReturn([$syncList]);

        // Last run was 10 minutes ago — next run was 5 minutes ago, so due
        $lastRun = $this->makeSyncRun(
            $syncList,
            new \DateTimeImmutable('-10 minutes'),
        );

        $this->syncRunRepository
            ->shouldReceive('findLastBySyncList')
            ->with($syncList)
            ->andReturn($lastRun);

        $this->syncService
            ->shouldReceive('executeSync')
            ->once()
            ->withArgs(function (SyncList $sl, bool $dryRun, ?object $user, string $trigger) {
                return $sl->getName() === self::LIST_ONE
                    && $dryRun === false
                    && $user === null
                    && $trigger === 'schedule';
            })
            ->andReturn($this->makeSuccessResult());

        $tester = $this->executeCommand(['--scheduled' => true]);

        self::assertEquals(0, $tester->getStatusCode());
        self::assertStringContainsString('due', $tester->getDisplay());
    }

    public function testScheduledCombinesWithListFilter(): void
    {
        $syncList = $this->makeSyncList(self::LIST_ONE, '*/5 * * * *');

        $this->syncListRepository
            ->shouldReceive('findBy')
            ->with(['name' => self::LIST_ONE])
            ->andReturn([$syncList]);

        $this->syncRunRepository
            ->shouldReceive('findLastBySyncList')
            ->with($syncList)
            ->andReturnNull();

        $this->syncService
            ->shouldReceive('executeSync')
            ->once()
            ->withArgs(function (SyncList $sl, bool $dryRun, ?object $user, string $trigger) {
                return $sl->getName() === self::LIST_ONE
                    && $dryRun === false
                    && $user === null
                    && $trigger === 'schedule';
            })
            ->andReturn($this->makeSuccessResult());

        $tester = $this->executeCommand([
            '--scheduled' => true,
            '--list' => self::LIST_ONE,
        ]);

        self::assertEquals(0, $tester->getStatusCode());
    }

    public function testScheduledCombinesWithDryRun(): void
    {
        $syncList = $this->makeSyncList(self::LIST_ONE, '*/5 * * * *');

        $this->syncListRepository
            ->shouldReceive('findBy')
            ->with(['isEnabled' => true])
            ->andReturn([$syncList]);

        $this->syncRunRepository
            ->shouldReceive('findLastBySyncList')
            ->with($syncList)
            ->andReturnNull();

        $this->syncService
            ->shouldReceive('executeSync')
            ->once()
            ->withArgs(function (SyncList $sl, bool $dryRun, ?object $user, string $trigger) {
                return $sl->getName() === self::LIST_ONE
                    && $dryRun === true
                    && $user === null
                    && $trigger === 'schedule';
            })
            ->andReturn($this->makeSuccessResult());

        $tester = $this->executeCommand([
            '--scheduled' => true,
            '--dry-run' => true,
        ]);

        self::assertEquals(0, $tester->getStatusCode());
        self::assertStringContainsString('dry run', $tester->getDisplay());
    }

    public function testScheduledWithMixedDueAndNotDueLists(): void
    {
        $syncList1 = $this->makeSyncList(self::LIST_ONE, '*/5 * * * *');
        $syncList2 = $this->makeSyncList(self::LIST_TWO, '*/5 * * * *');

        $this->syncListRepository
            ->shouldReceive('findBy')
            ->with(['isEnabled' => true])
            ->andReturn([$syncList1, $syncList2]);

        // List 1: last run was 10 minutes ago — due
        $lastRun1 = $this->makeSyncRun(
            $syncList1,
            new \DateTimeImmutable('-10 minutes'),
        );

        $this->syncRunRepository
            ->shouldReceive('findLastBySyncList')
            ->with($syncList1)
            ->andReturn($lastRun1);

        // List 2: last run was just now — not due
        $lastRun2 = $this->makeSyncRun($syncList2, new \DateTimeImmutable());

        $this->syncRunRepository
            ->shouldReceive('findLastBySyncList')
            ->with($syncList2)
            ->andReturn($lastRun2);

        // Only list 1 should be synced
        $this->syncService
            ->shouldReceive('executeSync')
            ->once()
            ->withArgs(function (SyncList $sl, bool $dryRun, ?object $user, string $trigger) {
                return $sl->getName() === self::LIST_ONE
                    && $dryRun === false
                    && $user === null
                    && $trigger === 'schedule';
            })
            ->andReturn($this->makeSuccessResult());

        $tester = $this->executeCommand(['--scheduled' => true]);

        self::assertEquals(0, $tester->getStatusCode());
        $display = $tester->getDisplay();
        self::assertStringContainsString('1/1', $display);
        self::assertStringContainsString('not due until', $display);
    }
}
