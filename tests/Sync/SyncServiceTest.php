<?php

namespace App\Tests\Sync;

use App\Client\Google\GoogleClient;
use App\Client\Google\GoogleClientFactory;
use App\Client\Google\InvalidGoogleTokenException;
use App\Client\PlanningCenter\PlanningCenterClient;
use App\Client\PlanningCenter\PlanningCenterClientFactory;
use App\Contact\Contact;
use App\Entity\InMemoryContact;
use App\Entity\Organization;
use App\Entity\SyncList;
use App\Entity\SyncRun;
use App\Entity\User;
use App\Repository\InMemoryContactRepository;
use App\Sync\SyncService;
use Doctrine\ORM\EntityManagerInterface;
use Mockery\Adapter\Phpunit\MockeryTestCase;
use Mockery as m;

class SyncServiceTest extends MockeryTestCase
{
    private const LIST_NAME = 'test-list@example.com';
    private const GOOGLE_DOMAIN = 'example.com';

    /** @var GoogleClientFactory|m\LegacyMockInterface|m\MockInterface */
    private $googleClientFactory;

    /** @var PlanningCenterClientFactory|m\LegacyMockInterface|m\MockInterface */
    private $planningCenterClientFactory;

    /** @var InMemoryContactRepository|m\LegacyMockInterface|m\MockInterface */
    private $inMemoryContactRepository;

    /** @var EntityManagerInterface|m\LegacyMockInterface|m\MockInterface */
    private $entityManager;

    /** @var GoogleClient|m\LegacyMockInterface|m\MockInterface */
    private $googleClient;

    /** @var PlanningCenterClient|m\LegacyMockInterface|m\MockInterface */
    private $planningCenterClient;

    private Organization $organization;
    private SyncList $syncList;
    private SyncService $syncService;

    public function setUp(): void
    {
        $this->googleClientFactory = m::mock(GoogleClientFactory::class);
        $this->planningCenterClientFactory = m::mock(
            PlanningCenterClientFactory::class,
        );
        $this->inMemoryContactRepository = m::mock(
            InMemoryContactRepository::class,
        );
        $this->entityManager = m::mock(EntityManagerInterface::class);
        $this->googleClient = m::mock(GoogleClient::class);
        $this->planningCenterClient = m::mock(PlanningCenterClient::class);

        $this->organization = new Organization();
        $this->organization->setName('Test Org');
        $this->organization->setPlanningCenterAppId('pc-id');
        $this->organization->setPlanningCenterAppSecret('pc-secret');
        $this->organization->setGoogleOAuthCredentials('{"installed":{}}');
        $this->organization->setGoogleDomain(self::GOOGLE_DOMAIN);
        $this->organization->setGoogleToken('{"access_token":"old-token"}');

        $this->syncList = new SyncList();
        $this->syncList->setName(self::LIST_NAME);
        $this->syncList->setOrganization($this->organization);

        $this->syncService = new SyncService(
            $this->googleClientFactory,
            $this->planningCenterClientFactory,
            $this->inMemoryContactRepository,
            $this->entityManager,
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
            ->with(self::LIST_NAME, $destContact);

        $this->googleClient
            ->shouldReceive('addContact')
            ->once()
            ->with(self::LIST_NAME, $sourceContact);

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

    public function testExecuteSyncMergesInMemoryContacts(): void
    {
        $pcContact = $this->makeContact('pc@test.com');
        $memEntity = new InMemoryContact();
        $memEntity->setName('Memory User');
        $memEntity->setEmail('mem@test.com');
        $memEntity->setOrganization($this->organization);

        $this->setupDefaultExpectations(
            sourceContacts: [$pcContact],
            destContacts: [],
            inMemoryEntities: [$memEntity],
        );

        $this->googleClient->shouldReceive('addContact')->twice();

        $result = $this->syncService->executeSync($this->syncList);

        self::assertTrue($result->success);
        self::assertEquals(2, $result->sourceCount);
        self::assertEquals(2, $result->addedCount);
        self::assertStringContainsString('in-memory contacts', $result->log);
    }

    public function testExecuteSyncDeduplicatesInMemoryContacts(): void
    {
        $pcContact = $this->makeContact('same@test.com');
        $memEntity = new InMemoryContact();
        $memEntity->setName('Same User');
        $memEntity->setEmail('same@test.com');
        $memEntity->setOrganization($this->organization);

        $this->setupDefaultExpectations(
            sourceContacts: [$pcContact],
            destContacts: [],
            inMemoryEntities: [$memEntity],
        );

        $this->googleClient->shouldReceive('addContact')->once();

        $result = $this->syncService->executeSync($this->syncList);

        self::assertTrue($result->success);
        self::assertEquals(1, $result->sourceCount);
        self::assertEquals(1, $result->addedCount);
    }

    public function testExecuteSyncPersistsSyncRunOnSuccess(): void
    {
        // setupDefaultExpectations already verifies that persist is called with a SyncRun
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

        $this->googleClientFactory
            ->shouldReceive('create')
            ->with($this->organization)
            ->andReturn($this->googleClient);

        $this->googleClient
            ->shouldReceive('initialize')
            ->once()
            ->andReturn($this->googleClient);

        $this->googleClient
            ->shouldReceive('getTokenData')
            ->andReturn(['access_token' => 'old-token']);

        $this->planningCenterClientFactory
            ->shouldReceive('create')
            ->with($this->organization)
            ->andReturn($this->planningCenterClient);

        $this->planningCenterClient
            ->shouldReceive('getContacts')
            ->with(self::LIST_NAME)
            ->andReturn([]);

        $this->inMemoryContactRepository
            ->shouldReceive('findBySyncList')
            ->with($this->syncList)
            ->andReturn([]);

        $this->googleClient
            ->shouldReceive('getContacts')
            ->with(self::LIST_NAME)
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

    public function testExecuteSyncHandlesGoogleInitializationError(): void
    {
        $this->entityManager
            ->shouldReceive('persist')
            ->once()
            ->with(m::type(SyncRun::class));

        $this->entityManager->shouldReceive('flush')->atLeast()->once();

        $this->googleClientFactory
            ->shouldReceive('create')
            ->with($this->organization)
            ->andReturn($this->googleClient);

        $this->googleClient
            ->shouldReceive('initialize')
            ->once()
            ->andThrow(new InvalidGoogleTokenException());

        $result = $this->syncService->executeSync($this->syncList);

        self::assertFalse($result->success);
        self::assertNotNull($result->errorMessage);
        self::assertStringContainsString('token', $result->errorMessage);
        self::assertStringContainsString('ERROR', $result->log);
    }

    public function testExecuteSyncHandlesPlanningCenterError(): void
    {
        $this->entityManager
            ->shouldReceive('persist')
            ->once()
            ->with(m::type(SyncRun::class));

        $this->entityManager->shouldReceive('flush')->atLeast()->once();

        $this->googleClientFactory
            ->shouldReceive('create')
            ->with($this->organization)
            ->andReturn($this->googleClient);

        $this->googleClient
            ->shouldReceive('initialize')
            ->once()
            ->andReturn($this->googleClient);

        $this->googleClient
            ->shouldReceive('getTokenData')
            ->andReturn(['access_token' => 'old-token']);

        $this->planningCenterClientFactory
            ->shouldReceive('create')
            ->with($this->organization)
            ->andReturn($this->planningCenterClient);

        $this->planningCenterClient
            ->shouldReceive('getContacts')
            ->with(self::LIST_NAME)
            ->andThrow(new \RuntimeException('API connection failed'));

        $result = $this->syncService->executeSync($this->syncList);

        self::assertFalse($result->success);
        self::assertEquals('API connection failed', $result->errorMessage);
        self::assertStringContainsString('ERROR', $result->log);
    }

    public function testExecuteSyncPersistsRefreshedToken(): void
    {
        $newToken = [
            'access_token' => 'new-token',
            'refresh_token' => 'refresh',
        ];
        $newTokenJson = json_encode($newToken, JSON_THROW_ON_ERROR);

        $this->entityManager
            ->shouldReceive('persist')
            ->once()
            ->with(m::type(SyncRun::class));

        // Expect flush at least 3 times: once for SyncRun persist, once for token update, once for results
        $this->entityManager->shouldReceive('flush')->atLeast()->times(3);

        $this->googleClientFactory
            ->shouldReceive('create')
            ->with($this->organization)
            ->andReturn($this->googleClient);

        $this->googleClient
            ->shouldReceive('initialize')
            ->once()
            ->andReturn($this->googleClient);

        $this->googleClient
            ->shouldReceive('getTokenData')
            ->andReturn($newToken);

        $this->planningCenterClientFactory
            ->shouldReceive('create')
            ->with($this->organization)
            ->andReturn($this->planningCenterClient);

        $this->planningCenterClient
            ->shouldReceive('getContacts')
            ->with(self::LIST_NAME)
            ->andReturn([]);

        $this->inMemoryContactRepository
            ->shouldReceive('findBySyncList')
            ->with($this->syncList)
            ->andReturn([]);

        $this->googleClient
            ->shouldReceive('getContacts')
            ->with(self::LIST_NAME)
            ->andReturn([]);

        $result = $this->syncService->executeSync($this->syncList);

        self::assertTrue($result->success);
        self::assertEquals(
            $newTokenJson,
            $this->organization->getGoogleToken(),
        );
    }

    public function testExecuteSyncDoesNotPersistUnchangedToken(): void
    {
        $existingToken = ['access_token' => 'old-token'];
        $this->organization->setGoogleToken(
            json_encode($existingToken, JSON_THROW_ON_ERROR),
        );

        $this->entityManager
            ->shouldReceive('persist')
            ->once()
            ->with(m::type(SyncRun::class));

        // Expect flush exactly twice: once for SyncRun persist, once for results
        $this->entityManager->shouldReceive('flush')->twice();

        $this->googleClientFactory
            ->shouldReceive('create')
            ->with($this->organization)
            ->andReturn($this->googleClient);

        $this->googleClient
            ->shouldReceive('initialize')
            ->once()
            ->andReturn($this->googleClient);

        $this->googleClient
            ->shouldReceive('getTokenData')
            ->andReturn($existingToken);

        $this->planningCenterClientFactory
            ->shouldReceive('create')
            ->with($this->organization)
            ->andReturn($this->planningCenterClient);

        $this->planningCenterClient
            ->shouldReceive('getContacts')
            ->with(self::LIST_NAME)
            ->andReturn([]);

        $this->inMemoryContactRepository
            ->shouldReceive('findBySyncList')
            ->with($this->syncList)
            ->andReturn([]);

        $this->googleClient
            ->shouldReceive('getContacts')
            ->with(self::LIST_NAME)
            ->andReturn([]);

        $result = $this->syncService->executeSync($this->syncList);

        self::assertTrue($result->success);
    }

    public function testExecuteSyncLogContainsTimestamps(): void
    {
        $this->setupDefaultExpectations(sourceContacts: [], destContacts: []);

        $result = $this->syncService->executeSync($this->syncList);

        self::assertTrue($result->success);
        // Log lines should contain timestamps in H:i:s format
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
     * @param InMemoryContact[] $inMemoryEntities
     */
    private function setupDefaultExpectations(
        array $sourceContacts,
        array $destContacts,
        array $inMemoryEntities = [],
    ): void {
        $this->entityManager
            ->shouldReceive('persist')
            ->once()
            ->with(m::type(SyncRun::class));

        $this->entityManager->shouldReceive('flush')->atLeast()->once();

        $this->googleClientFactory
            ->shouldReceive('create')
            ->with($this->organization)
            ->andReturn($this->googleClient);

        $this->googleClient
            ->shouldReceive('initialize')
            ->once()
            ->andReturn($this->googleClient);

        $this->googleClient
            ->shouldReceive('getTokenData')
            ->andReturn(['access_token' => 'old-token']);

        $this->planningCenterClientFactory
            ->shouldReceive('create')
            ->with($this->organization)
            ->andReturn($this->planningCenterClient);

        $this->planningCenterClient
            ->shouldReceive('getContacts')
            ->with(self::LIST_NAME)
            ->andReturn($sourceContacts);

        $this->inMemoryContactRepository
            ->shouldReceive('findBySyncList')
            ->with($this->syncList)
            ->andReturn($inMemoryEntities);

        $this->googleClient
            ->shouldReceive('getContacts')
            ->with(self::LIST_NAME)
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
