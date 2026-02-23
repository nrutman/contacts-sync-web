<?php

namespace App\Tests\Command;

use App\Command\RunSyncCommand;
use App\Entity\Organization;
use App\Entity\SyncList;
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

    public function setUp(): void
    {
        $this->syncService = m::mock(SyncService::class);
        $this->entityManager = m::mock(EntityManagerInterface::class);
        $this->syncListRepository = m::mock(EntityRepository::class);

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
            ->with(
                m::on(
                    static fn (SyncList $sl) => $sl->getName() ===
                        self::LIST_ONE,
                ),
                false,
                null,
                'cli',
            )
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
            ->with(
                m::on(
                    static fn (SyncList $sl) => $sl->getName() ===
                        self::LIST_ONE,
                ),
                true,
                null,
                'cli',
            )
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
            ->with(
                m::on(
                    static fn (SyncList $sl) => $sl->getName() ===
                        self::LIST_ONE,
                ),
                false,
                null,
                'cli',
            )
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
            ->with(
                m::on(
                    static fn (SyncList $sl) => $sl->getName() ===
                        self::LIST_TWO,
                ),
                false,
                null,
                'cli',
            )
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
        $command = new RunSyncCommand($this->syncService, $this->entityManager);

        $tester = new CommandTester($command);
        $tester->execute($options);

        return $tester;
    }

    private function makeSyncList(string $name): SyncList
    {
        $organization = new Organization();
        $organization->setName('Test Org');
        $organization->setPlanningCenterAppId('pc-id');
        $organization->setPlanningCenterAppSecret('pc-secret');
        $organization->setGoogleOAuthCredentials('{}');
        $organization->setGoogleDomain('example.com');

        $syncList = new SyncList();
        $syncList->setName($name);
        $syncList->setOrganization($organization);

        return $syncList;
    }
}
