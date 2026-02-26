<?php

namespace App\Tests\MessageHandler;

use App\Client\PlanningCenter\PlanningCenterProvider;
use App\Client\Provider\ProviderRegistry;
use App\Entity\Organization;
use App\Entity\ProviderCredential;
use App\Entity\SyncList;
use App\Message\RefreshListMessage;
use App\MessageHandler\RefreshListMessageHandler;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Mockery\Adapter\Phpunit\MockeryTestCase;
use Mockery as m;
use Psr\Log\LoggerInterface;

class RefreshListMessageHandlerTest extends MockeryTestCase
{
    private ProviderRegistry|m\LegacyMockInterface $providerRegistry;
    private EntityManagerInterface|m\LegacyMockInterface $entityManager;
    private LoggerInterface|m\LegacyMockInterface $logger;
    private EntityRepository|m\LegacyMockInterface $syncListRepository;
    private RefreshListMessageHandler $handler;

    protected function setUp(): void
    {
        $this->providerRegistry = m::mock(ProviderRegistry::class);
        $this->entityManager = m::mock(EntityManagerInterface::class);
        $this->logger = m::mock(LoggerInterface::class);
        $this->syncListRepository = m::mock(EntityRepository::class);

        $this->entityManager
            ->shouldReceive('getRepository')
            ->with(SyncList::class)
            ->andReturn($this->syncListRepository);

        $this->handler = new RefreshListMessageHandler(
            $this->providerRegistry,
            $this->entityManager,
            $this->logger,
        );
    }

    public function testInvokeRefreshesListSuccessfully(): void
    {
        $organization = $this->makeOrganization();
        $sourceCredential = $this->makeSourceCredential($organization);
        $syncList = $this->makeSyncList($organization, $sourceCredential, 'My List');

        $this->syncListRepository
            ->shouldReceive('find')
            ->with('list-123')
            ->once()
            ->andReturn($syncList);

        $pcProvider = m::mock(PlanningCenterProvider::class);
        $pcProvider
            ->shouldReceive('refreshList')
            ->with($sourceCredential, 'source-list-id')
            ->once();

        $this->providerRegistry
            ->shouldReceive('get')
            ->with('planning_center')
            ->once()
            ->andReturn($pcProvider);

        $this->logger
            ->shouldReceive('info')
            ->once()
            ->with(
                m::pattern('/refreshed successfully/'),
                m::on(function (array $context) {
                    return $context['name'] === 'My List'
                        && $context['sync_list_id'] === 'list-123'
                        && $context['triggered_by_user_id'] === 'user-456';
                }),
            );

        $message = new RefreshListMessage(
            syncListId: 'list-123',
            triggeredByUserId: 'user-456',
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

        $this->providerRegistry->shouldNotReceive('get');
        $this->logger->shouldNotReceive('info');
        $this->logger->shouldNotReceive('error');

        $message = new RefreshListMessage(syncListId: 'nonexistent');

        ($this->handler)($message);
    }

    public function testInvokeLogsAndRethrowsOnFailure(): void
    {
        $organization = $this->makeOrganization();
        $sourceCredential = $this->makeSourceCredential($organization);
        $syncList = $this->makeSyncList($organization, $sourceCredential, 'Broken List');

        $this->syncListRepository
            ->shouldReceive('find')
            ->with('list-123')
            ->once()
            ->andReturn($syncList);

        $exception = new \RuntimeException('API error');

        $pcProvider = m::mock(PlanningCenterProvider::class);
        $pcProvider
            ->shouldReceive('refreshList')
            ->with($sourceCredential, 'source-list-id')
            ->once()
            ->andThrow($exception);

        $this->providerRegistry
            ->shouldReceive('get')
            ->with('planning_center')
            ->once()
            ->andReturn($pcProvider);

        $this->logger
            ->shouldReceive('error')
            ->once()
            ->with(
                m::pattern('/Failed to refresh/'),
                m::on(function (array $context) use ($exception) {
                    return $context['name'] === 'Broken List'
                        && $context['sync_list_id'] === 'list-123'
                        && $context['error'] === 'API error'
                        && $context['exception'] === $exception;
                }),
            );

        $message = new RefreshListMessage(
            syncListId: 'list-123',
            triggeredByUserId: 'user-456',
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('API error');

        ($this->handler)($message);
    }

    public function testInvokeWithNoTriggeredByUser(): void
    {
        $organization = $this->makeOrganization();
        $sourceCredential = $this->makeSourceCredential($organization);
        $syncList = $this->makeSyncList($organization, $sourceCredential, 'My List');

        $this->syncListRepository
            ->shouldReceive('find')
            ->with('list-123')
            ->once()
            ->andReturn($syncList);

        $pcProvider = m::mock(PlanningCenterProvider::class);
        $pcProvider
            ->shouldReceive('refreshList')
            ->with($sourceCredential, 'source-list-id')
            ->once();

        $this->providerRegistry
            ->shouldReceive('get')
            ->with('planning_center')
            ->once()
            ->andReturn($pcProvider);

        $this->logger
            ->shouldReceive('info')
            ->once()
            ->with(
                m::pattern('/refreshed successfully/'),
                m::on(function (array $context) {
                    return $context['triggered_by_user_id'] === null;
                }),
            );

        $message = new RefreshListMessage(syncListId: 'list-123');

        ($this->handler)($message);
    }

    public function testInvokeWithNoSourceCredentialLogsWarning(): void
    {
        $organization = $this->makeOrganization();

        $syncList = new SyncList();
        $syncList->setName('No Creds');
        $syncList->setOrganization($organization);

        $this->syncListRepository
            ->shouldReceive('find')
            ->with('list-123')
            ->once()
            ->andReturn($syncList);

        $this->providerRegistry->shouldNotReceive('get');

        $this->logger
            ->shouldReceive('warning')
            ->once()
            ->with(
                m::pattern('/no source credential/'),
                m::type('array'),
            );

        $message = new RefreshListMessage(syncListId: 'list-123');

        ($this->handler)($message);
    }

    private function makeOrganization(): Organization
    {
        $organization = new Organization();
        $organization->setName('Test Org');

        return $organization;
    }

    private function makeSourceCredential(Organization $organization): ProviderCredential
    {
        $credential = new ProviderCredential();
        $credential->setOrganization($organization);
        $credential->setProviderName('planning_center');
        $credential->setCredentialsArray(['app_id' => 'id', 'app_secret' => 'secret']);

        return $credential;
    }

    private function makeSyncList(Organization $organization, ProviderCredential $sourceCredential, string $name): SyncList
    {
        $syncList = new SyncList();
        $syncList->setName($name);
        $syncList->setOrganization($organization);
        $syncList->setSourceCredential($sourceCredential);
        $syncList->setSourceListIdentifier('source-list-id');

        return $syncList;
    }
}
