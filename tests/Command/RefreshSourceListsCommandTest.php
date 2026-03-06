<?php

namespace App\Tests\Command;

use App\Client\PlanningCenter\PlanningCenterProvider;
use App\Client\Provider\ProviderRegistry;
use App\Command\RefreshSourceListsCommand;
use App\Entity\Organization;
use App\Entity\ProviderCredential;
use App\Entity\SyncList;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Mockery\Adapter\Phpunit\MockeryTestCase;
use Mockery as m;
use Symfony\Component\Console\Tester\CommandTester;

class RefreshSourceListsCommandTest extends MockeryTestCase
{
    private const LIST_ONE = 'list1@domain.com';
    private const LIST_TWO = 'list2@domain.com';

    private ProviderRegistry|m\LegacyMockInterface $providerRegistry;
    private PlanningCenterProvider|m\LegacyMockInterface $planningCenterProvider;
    private EntityManagerInterface|m\LegacyMockInterface $entityManager;
    private EntityRepository|m\LegacyMockInterface $syncListRepository;
    private Organization $organization;
    private ProviderCredential $sourceCredential;

    public function setUp(): void
    {
        $this->providerRegistry = m::mock(ProviderRegistry::class);
        $this->planningCenterProvider = m::mock(PlanningCenterProvider::class);
        $this->planningCenterProvider->shouldReceive('getDisplayName')->andReturn('Planning Center')->byDefault();
        $this->entityManager = m::mock(EntityManagerInterface::class);
        $this->syncListRepository = m::mock(EntityRepository::class);

        $this->organization = new Organization();
        $this->organization->setName('Test Org');

        $this->sourceCredential = new ProviderCredential();
        $this->sourceCredential->setOrganization($this->organization);
        $this->sourceCredential->setProviderName('planning_center');
        $this->sourceCredential->setCredentialsArray(['app_id' => 'id', 'app_secret' => 'secret']);

        $this->entityManager
            ->shouldReceive('getRepository')
            ->with(SyncList::class)
            ->andReturn($this->syncListRepository);
    }

    public function testExecuteRefreshSingleList(): void
    {
        $syncList = $this->makeSyncList(self::LIST_ONE, 'source-id-1');

        $this->syncListRepository
            ->shouldReceive('findOneBy')
            ->with(['name' => self::LIST_ONE])
            ->andReturn($syncList);

        $this->providerRegistry
            ->shouldReceive('get')
            ->with('planning_center')
            ->andReturn($this->planningCenterProvider);

        $this->planningCenterProvider
            ->shouldReceive('refreshList')
            ->once()
            ->with($this->sourceCredential, 'source-id-1');

        $tester = $this->executeCommand(self::LIST_ONE);

        self::assertEquals(0, $tester->getStatusCode());
        self::assertStringContainsString(self::LIST_ONE, $tester->getDisplay());
        self::assertStringContainsString('Done', $tester->getDisplay());
    }

    public function testExecuteRefreshAllLists(): void
    {
        $syncList1 = $this->makeSyncList(self::LIST_ONE, 'source-id-1');
        $syncList2 = $this->makeSyncList(self::LIST_TWO, 'source-id-2');

        $this->syncListRepository
            ->shouldReceive('findBy')
            ->with(['isEnabled' => true])
            ->andReturn([$syncList1, $syncList2]);

        $this->providerRegistry
            ->shouldReceive('get')
            ->with('planning_center')
            ->andReturn($this->planningCenterProvider);

        $this->planningCenterProvider
            ->shouldReceive('refreshList')
            ->once()
            ->with($this->sourceCredential, 'source-id-1');

        $this->planningCenterProvider
            ->shouldReceive('refreshList')
            ->once()
            ->with($this->sourceCredential, 'source-id-2');

        $tester = $this->executeCommand('all');

        self::assertEquals(0, $tester->getStatusCode());
        self::assertStringContainsString(self::LIST_ONE, $tester->getDisplay());
        self::assertStringContainsString(self::LIST_TWO, $tester->getDisplay());
        self::assertStringContainsString('Done', $tester->getDisplay());
    }

    public function testExecuteUnknownList(): void
    {
        $this->syncListRepository
            ->shouldReceive('findOneBy')
            ->with(['name' => 'unknown@list'])
            ->andReturn(null);

        $tester = $this->executeCommand('unknown@list');

        self::assertEquals(1, $tester->getStatusCode());
        self::assertStringContainsString(
            'Unknown list specified: unknown@list',
            $tester->getDisplay(),
        );
    }

    public function testExecuteRefreshAllWithNoEnabledLists(): void
    {
        $this->syncListRepository
            ->shouldReceive('findBy')
            ->with(['isEnabled' => true])
            ->andReturn([]);

        $this->planningCenterProvider->shouldNotReceive('refreshList');

        $tester = $this->executeCommand('all');

        self::assertEquals(0, $tester->getStatusCode());
    }

    public function testExecuteSkipsListWithNoSourceCredential(): void
    {
        $syncList = new SyncList();
        $syncList->setName('No Creds');
        $syncList->setOrganization($this->organization);

        $this->syncListRepository
            ->shouldReceive('findOneBy')
            ->with(['name' => 'No Creds'])
            ->andReturn($syncList);

        $this->providerRegistry->shouldNotReceive('get');

        $tester = $this->executeCommand('No Creds');

        self::assertEquals(0, $tester->getStatusCode());
        self::assertStringContainsString('no source credential', $tester->getDisplay());
    }

    private function executeCommand(string $listName): CommandTester
    {
        $command = new RefreshSourceListsCommand(
            $this->providerRegistry,
            $this->entityManager,
        );

        $tester = new CommandTester($command);
        $tester->execute(['list-name' => $listName]);

        return $tester;
    }

    private function makeSyncList(string $name, string $sourceListId): SyncList
    {
        $syncList = new SyncList();
        $syncList->setName($name);
        $syncList->setOrganization($this->organization);
        $syncList->setSourceCredential($this->sourceCredential);
        $syncList->setSourceListIdentifier($sourceListId);

        return $syncList;
    }
}
