<?php

namespace App\Tests\Sync;

use App\Client\Google\GoogleClient;
use App\Client\PlanningCenter\PlanningCenterClient;
use App\Client\Provider\ProviderInterface;
use App\Client\Provider\ProviderRegistry;
use App\Contact\Contact;
use App\Entity\ManualContact;
use App\Entity\Organization;
use App\Entity\ProviderCredential;
use App\Entity\SyncList;
use App\Entity\SyncRun;
use App\Entity\User;
use App\Event\SyncCompletedEvent;
use App\Repository\ManualContactRepository;
use App\Sync\SyncService;
use Doctrine\ORM\EntityManagerInterface;
use Mockery\Adapter\Phpunit\MockeryTestCase;
use Mockery as m;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

class SyncServiceTest extends MockeryTestCase
{
    private const LIST_NAME = 'test-list@example.com';
    private const SOURCE_LIST_ID = 'PC List Name';
    private const DEST_LIST_ID = 'test-list@example.com';

    private ProviderRegistry|m\LegacyMockInterface $providerRegistry;
    private ManualContactRepository|m\LegacyMockInterface $manualContactRepository;
    private EntityManagerInterface|m\LegacyMockInterface $entityManager;
    private EventDispatcherInterface|m\LegacyMockInterface $eventDispatcher;
    private LoggerInterface|m\LegacyMockInterface $logger;
    private GoogleClient|m\LegacyMockInterface $googleClient;
    private PlanningCenterClient|m\LegacyMockInterface $planningCenterClient;
    private ProviderInterface|m\LegacyMockInterface $sourceProvider;
    private ProviderInterface|m\LegacyMockInterface $destProvider;

    private Organization $organization;
    private ProviderCredential $sourceCredential;
    private ProviderCredential $destCredential;
    private SyncList $syncList;
    private SyncService $syncService;

    public function setUp(): void
    {
        $this->providerRegistry = m::mock(ProviderRegistry::class);
        $this->manualContactRepository = m::mock(
            ManualContactRepository::class,
        );
        $this->entityManager = m::mock(EntityManagerInterface::class);
        $this->eventDispatcher = m::mock(EventDispatcherInterface::class);
        $this->logger = m::mock(LoggerInterface::class);
        $this->logger->shouldReceive('info')->byDefault();
        $this->logger->shouldReceive('error')->byDefault();
        $this->googleClient = m::mock(GoogleClient::class);
        $this->planningCenterClient = m::mock(PlanningCenterClient::class);

        $this->sourceProvider = m::mock(ProviderInterface::class);
        $this->sourceProvider->shouldReceive('getDisplayName')->andReturn('Planning Center')->byDefault();

        $this->destProvider = m::mock(ProviderInterface::class);
        $this->destProvider->shouldReceive('getDisplayName')->andReturn('Google Groups')->byDefault();

        $this->organization = new Organization();
        $this->organization->setName('Test Org');

        $this->sourceCredential = new ProviderCredential();
        $this->sourceCredential->setOrganization($this->organization);
        $this->sourceCredential->setProviderName('planning_center');
        $this->sourceCredential->setCredentialsArray(['app_id' => 'id', 'app_secret' => 'secret']);

        $this->destCredential = new ProviderCredential();
        $this->destCredential->setOrganization($this->organization);
        $this->destCredential->setProviderName('google_groups');
        $this->destCredential->setCredentialsArray(['oauth_credentials' => '{}', 'domain' => 'example.com']);

        $this->syncList = new SyncList();
        $this->syncList->setName(self::LIST_NAME);
        $this->syncList->setOrganization($this->organization);
        $this->syncList->setSourceCredential($this->sourceCredential);
        $this->syncList->setSourceListIdentifier(self::SOURCE_LIST_ID);
        $this->syncList->setDestinationCredential($this->destCredential);
        $this->syncList->setDestinationListIdentifier(self::DEST_LIST_ID);

        $this->syncService = new SyncService(
            $this->providerRegistry,
            $this->manualContactRepository,
            $this->entityManager,
            $this->eventDispatcher,
            $this->logger,
        );
    }

    public function testExecuteSyncSuccessWithChanges(): void
    {
        $sourceContact = $this->makeContact('source@test.com', 'John', 'Doe');
        $destContact = $this->makeContact('old@test.com');

        $this->setupDefaultExpectations(
            sourceContacts: [$sourceContact],
            destContacts: [$destContact],
        );

        $this->googleClient
            ->shouldReceive('removeContact')
            ->once()
            ->with(self::DEST_LIST_ID, $destContact);

        $this->googleClient
            ->shouldReceive('addContact')
            ->once()
            ->with(self::DEST_LIST_ID, $sourceContact);

        $result = $this->syncService->executeSync($this->syncList);

        self::assertTrue($result->success);
        self::assertNull($result->errorMessage);
        self::assertEquals(1, $result->sourceCount);
        self::assertEquals(1, $result->destinationCount);
        self::assertEquals(1, $result->addedCount);
        self::assertEquals(1, $result->removedCount);
        self::assertStringContainsString('source@test.com', $result->log);
        self::assertStringContainsString('old@test.com', $result->log);
    }

    public function testExecuteSyncNoChangesNeeded(): void
    {
        $contact = $this->makeContact('shared@test.com');
        $destContact = $this->makeContact('shared@test.com');

        $this->setupDefaultExpectations(
            sourceContacts: [$contact],
            destContacts: [$destContact],
        );

        $this->googleClient->shouldNotReceive('addContact');
        $this->googleClient->shouldNotReceive('removeContact');

        $result = $this->syncService->executeSync($this->syncList);

        self::assertTrue($result->success);
        self::assertEquals(1, $result->sourceCount);
        self::assertEquals(1, $result->destinationCount);
        self::assertEquals(0, $result->addedCount);
        self::assertEquals(0, $result->removedCount);
    }

    public function testExecuteSyncDryRunDoesNotApplyChanges(): void
    {
        $sourceContact = $this->makeContact('new@test.com');

        $this->setupDefaultExpectations(
            sourceContacts: [$sourceContact],
            destContacts: [],
        );

        $this->googleClient->shouldNotReceive('addContact');
        $this->googleClient->shouldNotReceive('removeContact');

        $result = $this->syncService->executeSync(
            $this->syncList,
            dryRun: true,
        );

        self::assertTrue($result->success);
        self::assertEquals(1, $result->addedCount);
        self::assertStringContainsString('Dry run', $result->log);
    }

    public function testExecuteSyncMergesManualContacts(): void
    {
        $pcContact = $this->makeContact('pc@test.com');
        $memEntity = new ManualContact();
        $memEntity->setName('Memory User');
        $memEntity->setEmail('mem@test.com');
        $memEntity->setOrganization($this->organization);

        $this->setupDefaultExpectations(
            sourceContacts: [$pcContact],
            destContacts: [],
            manualEntities: [$memEntity],
        );

        $this->googleClient->shouldReceive('addContact')->twice();

        $result = $this->syncService->executeSync($this->syncList);

        self::assertTrue($result->success);
        self::assertEquals(2, $result->sourceCount);
        self::assertEquals(2, $result->addedCount);
        self::assertStringContainsString('manual contacts', $result->log);
    }

    public function testExecuteSyncDeduplicatesManualContacts(): void
    {
        $pcContact = $this->makeContact('same@test.com');
        $memEntity = new ManualContact();
        $memEntity->setName('Same User');
        $memEntity->setEmail('same@test.com');
        $memEntity->setOrganization($this->organization);

        $this->setupDefaultExpectations(
            sourceContacts: [$pcContact],
            destContacts: [],
            manualEntities: [$memEntity],
        );

        $this->googleClient->shouldReceive('addContact')->once();

        $result = $this->syncService->executeSync($this->syncList);

        self::assertTrue($result->success);
        self::assertEquals(1, $result->sourceCount);
        self::assertEquals(1, $result->addedCount);
    }

    public function testExecuteSyncPersistsSyncRunOnSuccess(): void
    {
        $this->setupDefaultExpectations(sourceContacts: [], destContacts: []);

        $result = $this->syncService->executeSync($this->syncList);

        self::assertTrue($result->success);
    }

    public function testExecuteSyncSetsTriggeredByUser(): void
    {
        $user = new User();
        $user->setEmail('admin@test.com');
        $user->setFirstName('Admin');
        $user->setLastName('User');

        $persistedSyncRun = null;

        $this->entityManager
            ->shouldReceive('persist')
            ->once()
            ->with(
                m::on(function (SyncRun $syncRun) use (&$persistedSyncRun) {
                    $persistedSyncRun = $syncRun;

                    return true;
                }),
            );

        $this->entityManager->shouldReceive('flush')->atLeast()->once();

        $this->eventDispatcher
            ->shouldReceive('dispatch')
            ->once()
            ->with(m::type(SyncCompletedEvent::class));

        $this->providerRegistry
            ->shouldReceive('get')
            ->with('planning_center')
            ->andReturn($this->sourceProvider);

        $this->providerRegistry
            ->shouldReceive('get')
            ->with('google_groups')
            ->andReturn($this->destProvider);

        $this->sourceProvider
            ->shouldReceive('createClient')
            ->with($this->sourceCredential)
            ->andReturn($this->planningCenterClient);

        $this->destProvider
            ->shouldReceive('createClient')
            ->with($this->destCredential)
            ->andReturn($this->googleClient);

        $this->planningCenterClient
            ->shouldReceive('getContacts')
            ->with(self::SOURCE_LIST_ID)
            ->andReturn([]);

        $this->manualContactRepository
            ->shouldReceive('findBySyncList')
            ->with($this->syncList)
            ->andReturn([]);

        $this->googleClient
            ->shouldReceive('getContacts')
            ->with(self::DEST_LIST_ID)
            ->andReturn([]);

        $result = $this->syncService->executeSync(
            $this->syncList,
            triggeredBy: $user,
            trigger: 'web',
        );

        self::assertTrue($result->success);
        self::assertNotNull($persistedSyncRun);
        self::assertSame($user, $persistedSyncRun->getTriggeredByUser());
        self::assertEquals('web', $persistedSyncRun->getTriggeredBy());
    }

    public function testExecuteSyncHandlesSourceProviderError(): void
    {
        $this->entityManager
            ->shouldReceive('persist')
            ->once()
            ->with(m::type(SyncRun::class));

        $this->entityManager->shouldReceive('flush')->atLeast()->once();

        $this->eventDispatcher
            ->shouldReceive('dispatch')
            ->once()
            ->with(m::type(SyncCompletedEvent::class));

        $this->providerRegistry
            ->shouldReceive('get')
            ->with('planning_center')
            ->andReturn($this->sourceProvider);

        $this->providerRegistry
            ->shouldReceive('get')
            ->with('google_groups')
            ->andReturn($this->destProvider);

        $this->sourceProvider
            ->shouldReceive('createClient')
            ->with($this->sourceCredential)
            ->andReturn($this->planningCenterClient);

        $this->destProvider
            ->shouldReceive('createClient')
            ->with($this->destCredential)
            ->andReturn($this->googleClient);

        $this->planningCenterClient
            ->shouldReceive('getContacts')
            ->with(self::SOURCE_LIST_ID)
            ->andThrow(new \RuntimeException('API connection failed'));

        $result = $this->syncService->executeSync($this->syncList);

        self::assertFalse($result->success);
        self::assertEquals('API connection failed', $result->errorMessage);
        self::assertStringContainsString('ERROR', $result->log);
    }

    public function testExecuteSyncHandlesDestinationProviderError(): void
    {
        $this->entityManager
            ->shouldReceive('persist')
            ->once()
            ->with(m::type(SyncRun::class));

        $this->entityManager->shouldReceive('flush')->atLeast()->once();

        $this->eventDispatcher
            ->shouldReceive('dispatch')
            ->once()
            ->with(m::type(SyncCompletedEvent::class));

        $this->providerRegistry
            ->shouldReceive('get')
            ->with('planning_center')
            ->andReturn($this->sourceProvider);

        $this->providerRegistry
            ->shouldReceive('get')
            ->with('google_groups')
            ->andReturn($this->destProvider);

        $this->sourceProvider
            ->shouldReceive('createClient')
            ->with($this->sourceCredential)
            ->andThrow(new \RuntimeException('Google token is invalid'));

        $result = $this->syncService->executeSync($this->syncList);

        self::assertFalse($result->success);
        self::assertNotNull($result->errorMessage);
        self::assertStringContainsString('ERROR', $result->log);
    }

    public function testExecuteSyncHandlesMissingCredentials(): void
    {
        $syncList = new SyncList();
        $syncList->setName('no-creds');
        $syncList->setOrganization($this->organization);

        $this->entityManager
            ->shouldReceive('persist')
            ->once()
            ->with(m::type(SyncRun::class));

        $this->entityManager->shouldReceive('flush')->atLeast()->once();

        $this->eventDispatcher
            ->shouldReceive('dispatch')
            ->once()
            ->with(m::type(SyncCompletedEvent::class));

        $result = $this->syncService->executeSync($syncList);

        self::assertFalse($result->success);
        self::assertStringContainsString('missing source or destination', $result->errorMessage);
    }

    public function testExecuteSyncLogContainsTimestamps(): void
    {
        $this->setupDefaultExpectations(sourceContacts: [], destContacts: []);

        $result = $this->syncService->executeSync($this->syncList);

        self::assertTrue($result->success);
        self::assertMatchesRegularExpression(
            "/\[\d{2}:\d{2}:\d{2}\]/",
            $result->log,
        );
    }

    public function testExecuteSyncLogContainsListName(): void
    {
        $this->setupDefaultExpectations(sourceContacts: [], destContacts: []);

        $result = $this->syncService->executeSync($this->syncList);

        self::assertTrue($result->success);
        self::assertStringContainsString(self::LIST_NAME, $result->log);
    }

    public function testExecuteSyncMultipleAddsAndRemoves(): void
    {
        $source1 = $this->makeContact('keep@test.com');
        $source2 = $this->makeContact('new1@test.com');
        $source3 = $this->makeContact('new2@test.com');
        $dest1 = $this->makeContact('keep@test.com');
        $dest2 = $this->makeContact('remove1@test.com');
        $dest3 = $this->makeContact('remove2@test.com');

        $this->setupDefaultExpectations(
            sourceContacts: [$source1, $source2, $source3],
            destContacts: [$dest1, $dest2, $dest3],
        );

        $this->googleClient->shouldReceive('removeContact')->twice();

        $this->googleClient->shouldReceive('addContact')->twice();

        $result = $this->syncService->executeSync($this->syncList);

        self::assertTrue($result->success);
        self::assertEquals(3, $result->sourceCount);
        self::assertEquals(3, $result->destinationCount);
        self::assertEquals(2, $result->addedCount);
        self::assertEquals(2, $result->removedCount);
    }

    /**
     * Sets up the default mock expectations for a standard sync flow.
     *
     * @param Contact[] $sourceContacts
     * @param Contact[] $destContacts
     * @param ManualContact[] $manualEntities
     */
    private function setupDefaultExpectations(
        array $sourceContacts,
        array $destContacts,
        array $manualEntities = [],
    ): void {
        $this->entityManager
            ->shouldReceive('persist')
            ->once()
            ->with(m::type(SyncRun::class));

        $this->entityManager->shouldReceive('flush')->atLeast()->once();

        $this->eventDispatcher
            ->shouldReceive('dispatch')
            ->once()
            ->with(m::type(SyncCompletedEvent::class));

        $this->providerRegistry
            ->shouldReceive('get')
            ->with('planning_center')
            ->andReturn($this->sourceProvider);

        $this->providerRegistry
            ->shouldReceive('get')
            ->with('google_groups')
            ->andReturn($this->destProvider);

        $this->sourceProvider
            ->shouldReceive('createClient')
            ->with($this->sourceCredential)
            ->andReturn($this->planningCenterClient);

        $this->destProvider
            ->shouldReceive('createClient')
            ->with($this->destCredential)
            ->andReturn($this->googleClient);

        $this->planningCenterClient
            ->shouldReceive('getContacts')
            ->with(self::SOURCE_LIST_ID)
            ->andReturn($sourceContacts);

        $this->manualContactRepository
            ->shouldReceive('findBySyncList')
            ->with($this->syncList)
            ->andReturn($manualEntities);

        $this->googleClient
            ->shouldReceive('getContacts')
            ->with(self::DEST_LIST_ID)
            ->andReturn($destContacts);
    }

    private function makeContact(
        string $email,
        ?string $firstName = null,
        ?string $lastName = null,
    ): Contact {
        $contact = new Contact();
        $contact->email = $email;
        $contact->firstName = $firstName;
        $contact->lastName = $lastName;

        return $contact;
    }
}
