<?php

namespace App\Tests\Command;

use App\Client\PlanningCenter\PlanningCenterClient;
use App\Client\PlanningCenter\PlanningCenterClientFactory;
use App\Command\RefreshPlanningCenterListsCommand;
use App\Entity\Organization;
use App\Entity\SyncList;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Mockery\Adapter\Phpunit\MockeryTestCase;
use Mockery as m;
use Symfony\Component\Console\Tester\CommandTester;

class RefreshPlanningCenterListsCommandTest extends MockeryTestCase
{
    private const LIST_ONE = 'list1@domain.com';
    private const LIST_TWO = 'list2@domain.com';

    /** @var PlanningCenterClientFactory|m\LegacyMockInterface|m\MockInterface */
    private $planningCenterClientFactory;

    /** @var PlanningCenterClient|m\LegacyMockInterface|m\MockInterface */
    private $planningCenterClient;

    /** @var EntityManagerInterface|m\LegacyMockInterface|m\MockInterface */
    private $entityManager;

    /** @var EntityRepository|m\LegacyMockInterface|m\MockInterface */
    private $organizationRepository;

    /** @var EntityRepository|m\LegacyMockInterface|m\MockInterface */
    private $syncListRepository;

    private Organization $organization;

    public function setUp(): void
    {
        $this->planningCenterClientFactory = m::mock(
            PlanningCenterClientFactory::class,
        );
        $this->planningCenterClient = m::mock(PlanningCenterClient::class);
        $this->entityManager = m::mock(EntityManagerInterface::class);
        $this->organizationRepository = m::mock(EntityRepository::class);
        $this->syncListRepository = m::mock(EntityRepository::class);

        $this->organization = new Organization();
        $this->organization->setName('Test Org');
        $this->organization->setPlanningCenterAppId('pc-id');
        $this->organization->setPlanningCenterAppSecret('pc-secret');
        $this->organization->setGoogleOAuthCredentials('{}');
        $this->organization->setGoogleDomain('example.com');

        $this->entityManager
            ->shouldReceive('getRepository')
            ->with(Organization::class)
            ->andReturn($this->organizationRepository);

        $this->entityManager
            ->shouldReceive('getRepository')
            ->with(SyncList::class)
            ->andReturn($this->syncListRepository);
    }

    public function testExecuteNoOrganizationFound(): void
    {
        $this->organizationRepository
            ->shouldReceive('findOneBy')
            ->with([])
            ->andReturn(null);

        $tester = $this->executeCommand('all');

        self::assertEquals(1, $tester->getStatusCode());
        self::assertStringContainsString(
            'No organization found',
            $tester->getDisplay(),
        );
    }

    public function testExecuteRefreshSingleList(): void
    {
        $syncList = $this->makeSyncList(self::LIST_ONE);

        $this->organizationRepository
            ->shouldReceive('findOneBy')
            ->with([])
            ->andReturn($this->organization);

        $this->planningCenterClientFactory
            ->shouldReceive('create')
            ->with($this->organization)
            ->andReturn($this->planningCenterClient);

        $this->syncListRepository
            ->shouldReceive('findOneBy')
            ->with(['name' => self::LIST_ONE])
            ->andReturn($syncList);

        $this->planningCenterClient
            ->shouldReceive('refreshList')
            ->once()
            ->with(self::LIST_ONE);

        $tester = $this->executeCommand(self::LIST_ONE);

        self::assertEquals(0, $tester->getStatusCode());
        self::assertStringContainsString(self::LIST_ONE, $tester->getDisplay());
        self::assertStringContainsString('Done', $tester->getDisplay());
    }

    public function testExecuteRefreshAllLists(): void
    {
        $syncList1 = $this->makeSyncList(self::LIST_ONE);
        $syncList2 = $this->makeSyncList(self::LIST_TWO);

        $this->organizationRepository
            ->shouldReceive('findOneBy')
            ->with([])
            ->andReturn($this->organization);

        $this->planningCenterClientFactory
            ->shouldReceive('create')
            ->with($this->organization)
            ->andReturn($this->planningCenterClient);

        $this->syncListRepository
            ->shouldReceive('findBy')
            ->with(['isEnabled' => true])
            ->andReturn([$syncList1, $syncList2]);

        $this->planningCenterClient
            ->shouldReceive('refreshList')
            ->once()
            ->with(self::LIST_ONE);

        $this->planningCenterClient
            ->shouldReceive('refreshList')
            ->once()
            ->with(self::LIST_TWO);

        $tester = $this->executeCommand('all');

        self::assertEquals(0, $tester->getStatusCode());
        self::assertStringContainsString(self::LIST_ONE, $tester->getDisplay());
        self::assertStringContainsString(self::LIST_TWO, $tester->getDisplay());
        self::assertStringContainsString('Done', $tester->getDisplay());
    }

    public function testExecuteUnknownList(): void
    {
        $this->organizationRepository
            ->shouldReceive('findOneBy')
            ->with([])
            ->andReturn($this->organization);

        $this->planningCenterClientFactory
            ->shouldReceive('create')
            ->with($this->organization)
            ->andReturn($this->planningCenterClient);

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
        $this->organizationRepository
            ->shouldReceive('findOneBy')
            ->with([])
            ->andReturn($this->organization);

        $this->planningCenterClientFactory
            ->shouldReceive('create')
            ->with($this->organization)
            ->andReturn($this->planningCenterClient);

        $this->syncListRepository
            ->shouldReceive('findBy')
            ->with(['isEnabled' => true])
            ->andReturn([]);

        $this->planningCenterClient->shouldNotReceive('refreshList');

        $tester = $this->executeCommand('all');

        self::assertEquals(0, $tester->getStatusCode());
        self::assertStringContainsString('Done', $tester->getDisplay());
    }

    private function executeCommand(string $listName): CommandTester
    {
        $command = new RefreshPlanningCenterListsCommand(
            $this->planningCenterClientFactory,
            $this->entityManager,
        );

        $tester = new CommandTester($command);
        $tester->execute(['list-name' => $listName]);

        return $tester;
    }

    private function makeSyncList(string $name): SyncList
    {
        $syncList = new SyncList();
        $syncList->setName($name);
        $syncList->setOrganization($this->organization);

        return $syncList;
    }
}
