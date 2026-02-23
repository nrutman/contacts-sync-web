<?php

namespace App\Tests\MessageHandler;

use App\Client\PlanningCenter\PlanningCenterClient;
use App\Client\PlanningCenter\PlanningCenterClientFactory;
use App\Entity\Organization;
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
    private PlanningCenterClientFactory|m\LegacyMockInterface $planningCenterClientFactory;
    private EntityManagerInterface|m\LegacyMockInterface $entityManager;
    private LoggerInterface|m\LegacyMockInterface $logger;
    private EntityRepository|m\LegacyMockInterface $syncListRepository;
    private RefreshListMessageHandler $handler;

    protected function setUp(): void
    {
        $this->planningCenterClientFactory = m::mock(PlanningCenterClientFactory::class);
        $this->entityManager = m::mock(EntityManagerInterface::class);
        $this->logger = m::mock(LoggerInterface::class);
        $this->syncListRepository = m::mock(EntityRepository::class);

        $this->entityManager
            ->shouldReceive('getRepository')
            ->with(SyncList::class)
            ->andReturn($this->syncListRepository);

        $this->handler = new RefreshListMessageHandler(
            $this->planningCenterClientFactory,
            $this->entityManager,
            $this->logger,
        );
    }

    public function testInvokeRefreshesListSuccessfully(): void
    {
        $organization = new Organization();
        $organization->setName('Test Org');
        $organization->setPlanningCenterAppId('pc-id');
        $organization->setPlanningCenterAppSecret('pc-secret');
        $organization->setGoogleOAuthCredentials('{}');
        $organization->setGoogleDomain('example.com');

        $syncList = new SyncList();
        $syncList->setName('My List');
        $syncList->setOrganization($organization);

        $this->syncListRepository
            ->shouldReceive('find')
            ->with('list-123')
            ->once()
            ->andReturn($syncList);

        $pcClient = m::mock(PlanningCenterClient::class);
        $pcClient
            ->shouldReceive('refreshList')
            ->with('My List')
            ->once();

        $this->planningCenterClientFactory
            ->shouldReceive('create')
            ->with($organization)
            ->once()
            ->andReturn($pcClient);

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

        $this->planningCenterClientFactory->shouldNotReceive('create');
        $this->logger->shouldNotReceive('info');
        $this->logger->shouldNotReceive('error');

        $message = new RefreshListMessage(syncListId: 'nonexistent');

        ($this->handler)($message);
    }

    public function testInvokeLogsAndRethrowsOnFailure(): void
    {
        $organization = new Organization();
        $organization->setName('Test Org');
        $organization->setPlanningCenterAppId('pc-id');
        $organization->setPlanningCenterAppSecret('pc-secret');
        $organization->setGoogleOAuthCredentials('{}');
        $organization->setGoogleDomain('example.com');

        $syncList = new SyncList();
        $syncList->setName('Broken List');
        $syncList->setOrganization($organization);

        $this->syncListRepository
            ->shouldReceive('find')
            ->with('list-123')
            ->once()
            ->andReturn($syncList);

        $exception = new \RuntimeException('API error');

        $pcClient = m::mock(PlanningCenterClient::class);
        $pcClient
            ->shouldReceive('refreshList')
            ->with('Broken List')
            ->once()
            ->andThrow($exception);

        $this->planningCenterClientFactory
            ->shouldReceive('create')
            ->with($organization)
            ->once()
            ->andReturn($pcClient);

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
        $organization = new Organization();
        $organization->setName('Test Org');
        $organization->setPlanningCenterAppId('pc-id');
        $organization->setPlanningCenterAppSecret('pc-secret');
        $organization->setGoogleOAuthCredentials('{}');
        $organization->setGoogleDomain('example.com');

        $syncList = new SyncList();
        $syncList->setName('My List');
        $syncList->setOrganization($organization);

        $this->syncListRepository
            ->shouldReceive('find')
            ->with('list-123')
            ->once()
            ->andReturn($syncList);

        $pcClient = m::mock(PlanningCenterClient::class);
        $pcClient
            ->shouldReceive('refreshList')
            ->with('My List')
            ->once();

        $this->planningCenterClientFactory
            ->shouldReceive('create')
            ->with($organization)
            ->once()
            ->andReturn($pcClient);

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
}
