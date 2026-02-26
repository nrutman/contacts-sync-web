<?php

namespace App\Tests\Command;

use App\Command\MigrateConfigToDbCommand;
use App\Entity\InMemoryContact;
use App\Entity\Organization;
use App\Entity\SyncList;
use App\File\FileProvider;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Mockery\Adapter\Phpunit\MockeryTestCase;
use Mockery as m;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Exception\FileNotFoundException;
use Symfony\Component\Yaml\Yaml;

class MigrateConfigToDbCommandTest extends MockeryTestCase
{
    private const PLANNING_CENTER_APP_ID = 'pc-app-id-123';
    private const PLANNING_CENTER_APP_SECRET = 'pc-secret-456';
    private const GOOGLE_DOMAIN = 'example.org';
    private const GOOGLE_TOKEN_CONTENTS = '{"access_token":"ya29.test","refresh_token":"1//test"}';

    /** @var EntityManagerInterface|m\MockInterface */
    private $entityManager;

    /** @var FileProvider|m\MockInterface */
    private $fileProvider;

    /** @var EntityRepository|m\MockInterface */
    private $orgRepository;

    private string $tempDir;
    private string $configFilePath;

    private array $googleConfiguration;
    private array $lists;
    private array $inMemoryContacts;

    protected function setUp(): void
    {
        $this->entityManager = m::mock(EntityManagerInterface::class);
        $this->fileProvider = m::mock(FileProvider::class);
        $this->orgRepository = m::mock(EntityRepository::class);

        $this->entityManager
            ->shouldReceive('getRepository')
            ->with(Organization::class)
            ->andReturn($this->orgRepository);

        $this->tempDir = sys_get_temp_dir().'/migrate_config_test_'.uniqid();
        mkdir($this->tempDir);

        $this->googleConfiguration = [
            'installed' => [
                'client_id' => 'test-client-id.apps.googleusercontent.com',
                'project_id' => 'Test Project',
                'auth_uri' => 'https://accounts.google.com/o/oauth2/auth',
                'token_uri' => 'https://oauth2.googleapis.com/token',
                'client_secret' => 'test-client-secret',
                'redirect_uris' => ['urn:ietf:wg:oauth:2.0:oob', 'http://localhost'],
            ],
        ];

        $this->lists = [
            'church@example.org',
            'techteam@example.org',
            'worship@example.org',
        ];

        $this->inMemoryContacts = [
            'John Doe' => [
                'email' => 'john@example.org',
                'list' => ['techteam@example.org', 'worship@example.org'],
            ],
            'Jane Smith' => [
                'email' => 'jane@example.org',
                'list' => 'church@example.org',
            ],
        ];

        $this->configFilePath = $this->tempDir.'/parameters.yml';
        $this->writeConfigFile();
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        if (file_exists($this->configFilePath)) {
            unlink($this->configFilePath);
        }

        if (is_dir($this->tempDir)) {
            rmdir($this->tempDir);
        }
    }

    public function testFreshMigrationCreatesAllEntities(): void
    {
        $this->orgRepository
            ->shouldReceive('findOneBy')
            ->with([])
            ->andReturn(null);

        $this->fileProvider
            ->shouldReceive('getContents')
            ->with('/tmp/test-var/google-token.json')
            ->andReturn(self::GOOGLE_TOKEN_CONTENTS);

        $this->entityManager->shouldReceive('beginTransaction')->once();

        $persistedEntities = [];
        $this->entityManager
            ->shouldReceive('persist')
            ->andReturnUsing(function ($entity) use (&$persistedEntities) {
                $persistedEntities[] = $entity;
            });

        $this->entityManager->shouldReceive('flush')->once();
        $this->entityManager->shouldReceive('commit')->once();

        $tester = $this->executeCommand();

        self::assertSame(0, $tester->getStatusCode());

        // 1 Organization + 3 SyncLists + 2 InMemoryContacts = 6 entities
        self::assertCount(6, $persistedEntities);

        /** @var Organization $org */
        $org = $persistedEntities[0];
        self::assertInstanceOf(Organization::class, $org);
        self::assertSame(self::GOOGLE_DOMAIN, $org->getName());
        self::assertSame(self::PLANNING_CENTER_APP_ID, $org->getPlanningCenterAppId());
        self::assertSame(self::PLANNING_CENTER_APP_SECRET, $org->getPlanningCenterAppSecret());
        self::assertSame(json_encode($this->googleConfiguration, JSON_THROW_ON_ERROR), $org->getGoogleOAuthCredentials());
        self::assertSame(self::GOOGLE_DOMAIN, $org->getGoogleDomain());
        self::assertSame(self::GOOGLE_TOKEN_CONTENTS, $org->getGoogleToken());

        // Verify sync lists
        $syncLists = array_filter($persistedEntities, fn ($e) => $e instanceof SyncList);
        self::assertCount(3, $syncLists);

        $listNames = array_map(fn (SyncList $l) => $l->getName(), $syncLists);
        self::assertContains('church@example.org', $listNames);
        self::assertContains('techteam@example.org', $listNames);
        self::assertContains('worship@example.org', $listNames);

        foreach ($syncLists as $syncList) {
            self::assertTrue($syncList->isEnabled());
            self::assertNull($syncList->getCronExpression());
        }

        // Verify in-memory contacts
        $contacts = array_values(array_filter($persistedEntities, fn ($e) => $e instanceof InMemoryContact));
        self::assertCount(2, $contacts);

        $johnContact = $this->findContactByName($contacts, 'John Doe');
        self::assertNotNull($johnContact);
        self::assertSame('john@example.org', $johnContact->getEmail());
        self::assertCount(2, $johnContact->getSyncLists());

        $janeContact = $this->findContactByName($contacts, 'Jane Smith');
        self::assertNotNull($janeContact);
        self::assertSame('jane@example.org', $janeContact->getEmail());
        self::assertCount(1, $janeContact->getSyncLists());

        // Verify output
        $display = $tester->getDisplay();
        self::assertStringContainsString('example.org', $display);
        self::assertStringContainsString('3 created', $display);
        self::assertStringContainsString('2 created', $display);
        self::assertStringContainsString('3 list associations', $display);
        self::assertStringContainsString('imported', $display);
    }

    public function testMigrationWithoutGoogleToken(): void
    {
        $this->orgRepository
            ->shouldReceive('findOneBy')
            ->with([])
            ->andReturn(null);

        $this->fileProvider
            ->shouldReceive('getContents')
            ->with('/tmp/test-var/google-token.json')
            ->andThrow(new FileNotFoundException());

        $this->entityManager->shouldReceive('beginTransaction')->once();
        $this->entityManager->shouldReceive('persist')->andReturnNull();
        $this->entityManager->shouldReceive('flush')->once();
        $this->entityManager->shouldReceive('commit')->once();

        $tester = $this->executeCommand();

        self::assertSame(0, $tester->getStatusCode());

        $display = $tester->getDisplay();
        self::assertStringContainsString('not found', $display);
    }

    public function testAbortWhenOrganizationExistsAndUserDeclines(): void
    {
        $existingOrg = m::mock(Organization::class);

        $this->orgRepository
            ->shouldReceive('findOneBy')
            ->with([])
            ->andReturn($existingOrg);

        $this->entityManager->shouldNotReceive('beginTransaction');
        $this->entityManager->shouldNotReceive('persist');
        $this->entityManager->shouldNotReceive('flush');

        $tester = $this->executeCommand(interactive: true, inputs: ['no']);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('aborted', $tester->getDisplay());
    }

    public function testOverwriteWhenOrganizationExistsAndUserConfirms(): void
    {
        $existingOrg = m::mock(Organization::class);

        $this->orgRepository
            ->shouldReceive('findOneBy')
            ->with([])
            ->andReturn($existingOrg);

        $this->entityManager
            ->shouldReceive('remove')
            ->with($existingOrg)
            ->once();

        // flush for the remove, then flush for the new data
        $this->entityManager->shouldReceive('flush')->twice();
        $this->entityManager->shouldReceive('beginTransaction')->once();
        $this->entityManager->shouldReceive('persist')->andReturnNull();
        $this->entityManager->shouldReceive('commit')->once();

        $this->fileProvider
            ->shouldReceive('getContents')
            ->andThrow(new FileNotFoundException());

        $tester = $this->executeCommand(interactive: true, inputs: ['yes']);

        self::assertSame(0, $tester->getStatusCode());
    }

    public function testNonInteractiveModeAbortsWhenOrganizationExists(): void
    {
        $existingOrg = m::mock(Organization::class);

        $this->orgRepository
            ->shouldReceive('findOneBy')
            ->with([])
            ->andReturn($existingOrg);

        $this->entityManager->shouldNotReceive('beginTransaction');

        $tester = $this->executeCommand(interactive: false);

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('already exists', $tester->getDisplay());
    }

    public function testSingleStringListValueForContact(): void
    {
        $this->inMemoryContacts = [
            'Solo Contact' => [
                'email' => 'solo@example.org',
                'list' => 'church@example.org',
            ],
        ];
        $this->writeConfigFile();

        $this->orgRepository
            ->shouldReceive('findOneBy')
            ->with([])
            ->andReturn(null);

        $this->fileProvider
            ->shouldReceive('getContents')
            ->andThrow(new FileNotFoundException());

        $this->entityManager->shouldReceive('beginTransaction')->once();

        $persistedEntities = [];
        $this->entityManager
            ->shouldReceive('persist')
            ->andReturnUsing(function ($entity) use (&$persistedEntities) {
                $persistedEntities[] = $entity;
            });

        $this->entityManager->shouldReceive('flush')->once();
        $this->entityManager->shouldReceive('commit')->once();

        $tester = $this->executeCommand();

        self::assertSame(0, $tester->getStatusCode());

        $contacts = array_values(array_filter($persistedEntities, fn ($e) => $e instanceof InMemoryContact));
        self::assertCount(1, $contacts);
        self::assertSame('Solo Contact', $contacts[0]->getName());
        self::assertCount(1, $contacts[0]->getSyncLists());
    }

    public function testContactWithUnknownListIsSkipped(): void
    {
        $this->inMemoryContacts = [
            'Unknown List Person' => [
                'email' => 'unknown@example.org',
                'list' => 'nonexistent@example.org',
            ],
        ];
        $this->writeConfigFile();

        $this->orgRepository
            ->shouldReceive('findOneBy')
            ->with([])
            ->andReturn(null);

        $this->fileProvider
            ->shouldReceive('getContents')
            ->andThrow(new FileNotFoundException());

        $this->entityManager->shouldReceive('beginTransaction')->once();

        $persistedEntities = [];
        $this->entityManager
            ->shouldReceive('persist')
            ->andReturnUsing(function ($entity) use (&$persistedEntities) {
                $persistedEntities[] = $entity;
            });

        $this->entityManager->shouldReceive('flush')->once();
        $this->entityManager->shouldReceive('commit')->once();

        $tester = $this->executeCommand();

        self::assertSame(0, $tester->getStatusCode());

        $contacts = array_values(array_filter($persistedEntities, fn ($e) => $e instanceof InMemoryContact));
        self::assertCount(1, $contacts);
        // Contact is created but has no list associations
        self::assertCount(0, $contacts[0]->getSyncLists());

        self::assertStringContainsString('0 list associations', $tester->getDisplay());
    }

    public function testRollbackOnFailure(): void
    {
        $this->orgRepository
            ->shouldReceive('findOneBy')
            ->with([])
            ->andReturn(null);

        $this->fileProvider
            ->shouldReceive('getContents')
            ->andThrow(new FileNotFoundException());

        $this->entityManager->shouldReceive('beginTransaction')->once();
        $this->entityManager->shouldReceive('persist')->andReturnNull();
        $this->entityManager
            ->shouldReceive('flush')
            ->andThrow(new \RuntimeException('Database error'));
        $this->entityManager->shouldReceive('rollback')->once();
        $this->entityManager->shouldNotReceive('commit');

        $tester = $this->executeCommand();

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('Migration failed', $tester->getDisplay());
        self::assertStringContainsString('Database error', $tester->getDisplay());
    }

    public function testMissingConfigFileReturnsFail(): void
    {
        $command = new MigrateConfigToDbCommand(
            $this->entityManager,
            $this->fileProvider,
            '/tmp/test-var',
        );

        $tester = new CommandTester($command);
        $tester->execute(['config-file' => '/nonexistent/path/parameters.yml'], ['interactive' => false]);

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('not found', $tester->getDisplay());
    }

    public function testPlaceholderValuesReturnsFail(): void
    {
        $placeholderConfig = [
            'parameters' => [
                'planning_center.app.id' => '{{PlanningCenter Application ID}}',
                'planning_center.app.secret' => '{{PlanningCenter Secret}}',
                'google.authentication' => [],
                'google.domain' => '{{G Suite Domain}}',
                'lists' => ['list1'],
                'contacts' => [],
            ],
        ];

        $placeholderPath = $this->tempDir.'/placeholder.yml';
        file_put_contents($placeholderPath, Yaml::dump($placeholderConfig, 4));

        $command = new MigrateConfigToDbCommand(
            $this->entityManager,
            $this->fileProvider,
            '/tmp/test-var',
        );

        $tester = new CommandTester($command);
        $tester->execute(['config-file' => $placeholderPath], ['interactive' => false]);

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('placeholder', $tester->getDisplay());

        unlink($placeholderPath);
    }

    /**
     * @param InMemoryContact[] $contacts
     */
    private function findContactByName(array $contacts, string $name): ?InMemoryContact
    {
        foreach ($contacts as $contact) {
            if ($contact->getName() === $name) {
                return $contact;
            }
        }

        return null;
    }

    private function writeConfigFile(): void
    {
        $config = [
            'parameters' => [
                'planning_center.app.id' => self::PLANNING_CENTER_APP_ID,
                'planning_center.app.secret' => self::PLANNING_CENTER_APP_SECRET,
                'google.authentication' => $this->googleConfiguration,
                'google.domain' => self::GOOGLE_DOMAIN,
                'lists' => $this->lists,
                'contacts' => $this->inMemoryContacts,
            ],
        ];

        file_put_contents($this->configFilePath, Yaml::dump($config, 4));
    }

    private function executeCommand(bool $interactive = false, array $inputs = []): CommandTester
    {
        $command = new MigrateConfigToDbCommand(
            $this->entityManager,
            $this->fileProvider,
            '/tmp/test-var',
        );

        $tester = new CommandTester($command);

        if ($inputs !== []) {
            $tester->setInputs($inputs);
        }

        $tester->execute(['config-file' => $this->configFilePath], ['interactive' => $interactive]);

        return $tester;
    }
}
