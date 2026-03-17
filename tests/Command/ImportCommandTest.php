<?php

namespace App\Tests\Command;

use App\Command\ImportCommand;
use App\Entity\ManualContact;
use App\Entity\Organization;
use App\Entity\ProviderCredential;
use App\Entity\SyncList;
use App\Repository\OrganizationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Mockery\Adapter\Phpunit\MockeryTestCase;
use Mockery as m;
use Symfony\Component\Console\Tester\CommandTester;

class ImportCommandTest extends MockeryTestCase
{
    private OrganizationRepository|m\MockInterface $orgRepository;
    private EntityManagerInterface|m\MockInterface $entityManager;
    private string $tempDir;

    protected function setUp(): void
    {
        $this->orgRepository = m::mock(OrganizationRepository::class);
        $this->entityManager = m::mock(EntityManagerInterface::class);
        $this->tempDir = sys_get_temp_dir().'/import_test_'.uniqid();
        mkdir($this->tempDir);
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        array_map('unlink', glob($this->tempDir.'/*'));

        if (is_dir($this->tempDir)) {
            rmdir($this->tempDir);
        }
    }

    public function testFailsWhenFileDoesNotExist(): void
    {
        $tester = $this->executeCommand('/nonexistent/file.json');

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('not found', $tester->getDisplay());
    }

    public function testFailsWhenFileContainsInvalidJson(): void
    {
        $path = $this->tempDir.'/bad.json';
        file_put_contents($path, 'not json at all');

        $tester = $this->executeCommand($path);

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('Invalid JSON', $tester->getDisplay());
    }

    public function testFailsWhenVersionIsUnsupported(): void
    {
        $path = $this->tempDir.'/future.json';
        file_put_contents($path, json_encode([
            'version' => 99,
            'organization' => [],
            'providerCredentials' => [],
            'syncLists' => [],
            'manualContacts' => [],
        ]));

        $tester = $this->executeCommand($path);

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('Unsupported version', $tester->getDisplay());
    }

    public function testFailsWhenRequiredKeysAreMissing(): void
    {
        $path = $this->tempDir.'/incomplete.json';
        file_put_contents($path, json_encode(['version' => 1]));

        $tester = $this->executeCommand($path);

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('Missing required', $tester->getDisplay());
    }

    public function testAbortsInNonInteractiveModeWhenDataExists(): void
    {
        $path = $this->writeValidExportFile();

        $this->orgRepository
            ->shouldReceive('findOne')
            ->andReturn(m::mock(Organization::class));

        $tester = $this->executeCommand($path, interactive: false);

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('already exists', $tester->getDisplay());
    }

    public function testAbortsWhenUserDeclinesConfirmation(): void
    {
        $path = $this->writeValidExportFile();

        $this->orgRepository
            ->shouldReceive('findOne')
            ->andReturn(m::mock(Organization::class));

        $tester = $this->executeCommand($path, interactive: true, inputs: ['no']);

        self::assertSame(2, $tester->getStatusCode());
        self::assertStringContainsString('aborted', $tester->getDisplay());
    }

    public function testImportsAllEntitiesFromJson(): void
    {
        $path = $this->writeFullExportFile();

        $this->orgRepository
            ->shouldReceive('findOne')
            ->andReturn(null);

        $this->entityManager->shouldReceive('beginTransaction')->once();

        $persistedEntities = [];
        $this->entityManager
            ->shouldReceive('persist')
            ->andReturnUsing(function ($entity) use (&$persistedEntities) {
                $persistedEntities[] = $entity;
            });

        $this->entityManager->shouldReceive('flush')->once();
        $this->entityManager->shouldReceive('commit')->once();

        $tester = $this->executeCommand($path);

        self::assertSame(0, $tester->getStatusCode());

        // 1 Organization + 2 ProviderCredentials + 1 ManualContact + 1 SyncList = 5
        self::assertCount(5, $persistedEntities);

        // Organization with preserved UUID
        $org = $persistedEntities[0];
        self::assertInstanceOf(Organization::class, $org);
        self::assertSame('Test Org', $org->getName());
        self::assertSame(30, $org->getRetentionDays());
        self::assertSame('01234567-89ab-7def-8000-000000000001', (string) $org->getId());

        // Provider credentials with preserved UUIDs and decrypted data
        $credentials = array_values(array_filter($persistedEntities, fn ($e) => $e instanceof ProviderCredential));
        self::assertCount(2, $credentials);

        $pcCred = $this->findEntityByProperty($credentials, fn (ProviderCredential $c) => $c->getProviderName() === 'planning_center');
        self::assertNotNull($pcCred);
        self::assertSame('01234567-89ab-7def-8000-000000000010', (string) $pcCred->getId());
        self::assertSame('PC', $pcCred->getLabel());
        self::assertSame('id123', $pcCred->getCredentialsArray()['app_id']);

        $googleCred = $this->findEntityByProperty($credentials, fn (ProviderCredential $c) => $c->getProviderName() === 'google_groups');
        self::assertNotNull($googleCred);
        self::assertSame('01234567-89ab-7def-8000-000000000020', (string) $googleCred->getId());

        // Manual contacts
        $contacts = array_values(array_filter($persistedEntities, fn ($e) => $e instanceof ManualContact));
        self::assertCount(1, $contacts);
        self::assertSame('John Doe', $contacts[0]->getName());
        self::assertSame('john@example.org', $contacts[0]->getEmail());
        self::assertSame('01234567-89ab-7def-8000-000000000030', (string) $contacts[0]->getId());

        // Sync lists with credential references and manual contact associations
        $lists = array_values(array_filter($persistedEntities, fn ($e) => $e instanceof SyncList));
        self::assertCount(1, $lists);
        self::assertSame('test-list', $lists[0]->getName());
        self::assertSame('01234567-89ab-7def-8000-000000000040', (string) $lists[0]->getId());
        self::assertSame($pcCred, $lists[0]->getSourceCredential());
        self::assertSame($googleCred, $lists[0]->getDestinationCredential());
        self::assertSame('source-id', $lists[0]->getSourceListIdentifier());
        self::assertSame('dest-id', $lists[0]->getDestinationListIdentifier());
        self::assertTrue($lists[0]->isEnabled());
        self::assertSame('0 2 * * *', $lists[0]->getCronExpression());
        self::assertCount(1, $lists[0]->getManualContacts());

        // Verify summary output
        $display = $tester->getDisplay();
        self::assertStringContainsString('2 credential(s)', $display);
        self::assertStringContainsString('1 list(s)', $display);
        self::assertStringContainsString('1 manual contact(s)', $display);
    }

    public function testImportWithForceOverwritesExistingData(): void
    {
        $path = $this->writeFullExportFile();

        $existingOrg = m::mock(Organization::class);

        $this->orgRepository
            ->shouldReceive('findOne')
            ->andReturn($existingOrg);

        $this->entityManager->shouldReceive('beginTransaction')->once();
        $this->entityManager
            ->shouldReceive('remove')
            ->with($existingOrg)
            ->once();

        // flush for remove, then flush for import
        $this->entityManager->shouldReceive('flush')->twice();
        $this->entityManager->shouldReceive('persist')->andReturnNull();
        $this->entityManager->shouldReceive('commit')->once();

        $tester = $this->executeCommand($path, force: true);

        self::assertSame(0, $tester->getStatusCode());
    }

    public function testRollbackOnImportFailure(): void
    {
        $path = $this->writeFullExportFile();

        $this->orgRepository
            ->shouldReceive('findOne')
            ->andReturn(null);

        $this->entityManager->shouldReceive('beginTransaction')->once();
        $this->entityManager->shouldReceive('persist')->andReturnNull();
        $this->entityManager
            ->shouldReceive('flush')
            ->andThrow(new \RuntimeException('Database error'));
        $this->entityManager->shouldReceive('rollback')->once();
        $this->entityManager->shouldNotReceive('commit');

        $tester = $this->executeCommand($path);

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('Import failed', $tester->getDisplay());
    }

    public function testFailsOnUnresolvableCredentialReference(): void
    {
        $path = $this->tempDir.'/bad-ref.json';
        file_put_contents($path, json_encode([
            'version' => 1,
            'exportedAt' => '2026-03-14T00:00:00+00:00',
            'organization' => [
                'id' => '01234567-89ab-7def-8000-000000000001',
                'name' => 'Test Org',
                'retentionDays' => null,
            ],
            'providerCredentials' => [],
            'syncLists' => [
                [
                    'id' => '01234567-89ab-7def-8000-000000000040',
                    'name' => 'broken-list',
                    'sourceCredentialId' => '01234567-89ab-7def-8000-999999999999',
                    'sourceListIdentifier' => null,
                    'destinationCredentialId' => null,
                    'destinationListIdentifier' => null,
                    'isEnabled' => true,
                    'cronExpression' => null,
                    'manualContactIds' => [],
                ],
            ],
            'manualContacts' => [],
        ]));

        $this->orgRepository
            ->shouldReceive('findOne')
            ->andReturn(null);

        $this->entityManager->shouldReceive('beginTransaction')->once();
        $this->entityManager->shouldReceive('persist')->andReturnNull();
        $this->entityManager->shouldNotReceive('commit');
        $this->entityManager->shouldReceive('rollback')->once();

        $tester = $this->executeCommand($path);

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('references unknown source', $tester->getDisplay());
    }

    public function testImportsOrganizationOnlyWithEmptyArrays(): void
    {
        $path = $this->writeValidExportFile();

        $this->orgRepository
            ->shouldReceive('findOne')
            ->andReturn(null);

        $this->entityManager->shouldReceive('beginTransaction')->once();

        $persistedEntities = [];
        $this->entityManager
            ->shouldReceive('persist')
            ->andReturnUsing(function ($entity) use (&$persistedEntities) {
                $persistedEntities[] = $entity;
            });

        $this->entityManager->shouldReceive('flush')->once();
        $this->entityManager->shouldReceive('commit')->once();

        $tester = $this->executeCommand($path);

        self::assertSame(0, $tester->getStatusCode());
        self::assertCount(1, $persistedEntities);
        self::assertInstanceOf(Organization::class, $persistedEntities[0]);
    }

    public function testImportWithInteractiveConfirmation(): void
    {
        $path = $this->writeFullExportFile();

        $existingOrg = m::mock(Organization::class);

        $this->orgRepository
            ->shouldReceive('findOne')
            ->andReturn($existingOrg);

        $this->entityManager->shouldReceive('beginTransaction')->once();
        $this->entityManager
            ->shouldReceive('remove')
            ->with($existingOrg)
            ->once();

        $this->entityManager->shouldReceive('flush')->twice();
        $this->entityManager->shouldReceive('persist')->andReturnNull();
        $this->entityManager->shouldReceive('commit')->once();

        $tester = $this->executeCommand($path, interactive: true, inputs: ['yes']);

        self::assertSame(0, $tester->getStatusCode());
    }

    private function findEntityByProperty(array $entities, callable $predicate): mixed
    {
        foreach ($entities as $entity) {
            if ($predicate($entity)) {
                return $entity;
            }
        }

        return null;
    }

    private function writeFullExportFile(): string
    {
        $path = $this->tempDir.'/full.json';
        file_put_contents($path, json_encode([
            'version' => 1,
            'exportedAt' => '2026-03-14T00:00:00+00:00',
            'organization' => [
                'id' => '01234567-89ab-7def-8000-000000000001',
                'name' => 'Test Org',
                'retentionDays' => 30,
            ],
            'providerCredentials' => [
                [
                    'id' => '01234567-89ab-7def-8000-000000000010',
                    'providerName' => 'planning_center',
                    'label' => 'PC',
                    'credentials' => ['app_id' => 'id123', 'app_secret' => 'secret456'],
                    'metadata' => ['refresh_at' => '2026-01-01'],
                ],
                [
                    'id' => '01234567-89ab-7def-8000-000000000020',
                    'providerName' => 'google_groups',
                    'label' => 'Google',
                    'credentials' => ['token' => 'abc'],
                    'metadata' => null,
                ],
            ],
            'syncLists' => [
                [
                    'id' => '01234567-89ab-7def-8000-000000000040',
                    'name' => 'test-list',
                    'sourceCredentialId' => '01234567-89ab-7def-8000-000000000010',
                    'sourceListIdentifier' => 'source-id',
                    'destinationCredentialId' => '01234567-89ab-7def-8000-000000000020',
                    'destinationListIdentifier' => 'dest-id',
                    'isEnabled' => true,
                    'cronExpression' => '0 2 * * *',
                    'manualContactIds' => ['01234567-89ab-7def-8000-000000000030'],
                ],
            ],
            'manualContacts' => [
                [
                    'id' => '01234567-89ab-7def-8000-000000000030',
                    'name' => 'John Doe',
                    'email' => 'john@example.org',
                ],
            ],
        ]));

        return $path;
    }

    private function writeValidExportFile(): string
    {
        $path = $this->tempDir.'/valid.json';
        file_put_contents($path, json_encode([
            'version' => 1,
            'exportedAt' => '2026-03-14T00:00:00+00:00',
            'organization' => [
                'id' => '01234567-89ab-7def-8000-000000000001',
                'name' => 'Test Org',
                'retentionDays' => null,
            ],
            'providerCredentials' => [],
            'syncLists' => [],
            'manualContacts' => [],
        ]));

        return $path;
    }

    private function executeCommand(string $path, bool $interactive = false, array $inputs = [], bool $force = false): CommandTester
    {
        $command = new ImportCommand($this->orgRepository, $this->entityManager);
        $tester = new CommandTester($command);

        if ($inputs !== []) {
            $tester->setInputs($inputs);
        }

        $args = ['path' => $path];

        if ($force) {
            $args['--force'] = true;
        }

        $tester->execute($args, ['interactive' => $interactive]);

        return $tester;
    }
}
