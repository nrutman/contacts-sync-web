# Import/Export CLI Commands Implementation Plan

> **For agentic workers:** REQUIRED: Use superpowers:subagent-driven-development (if subagents available) or superpowers:executing-plans to implement this plan. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Two CLI commands (`app:export` and `app:import`) to move configuration data between app instances via JSON.

**Architecture:** Manual serialization in command classes. Export decrypts credentials via entity getters; import sets plaintext and lets `EncryptedFieldListener` re-encrypt on flush. UUIDs are preserved via Reflection. Wipe-and-replace import uses Doctrine cascade-remove on Organization.

**Tech Stack:** PHP 8.5, Symfony 7.2, Doctrine ORM, Mockery, PHPUnit

**Spec:** `docs/superpowers/specs/2026-03-14-import-export-design.md`

---

## Chunk 1: Export Command

### Task 1: ExportCommand — no-organization error path

**Files:**
- Create: `src/Command/ExportCommand.php`
- Create: `tests/Command/ExportCommandTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Command/ExportCommandTest.php`:

```php
<?php

namespace App\Tests\Command;

use App\Command\ExportCommand;
use App\Entity\Organization;
use App\Repository\OrganizationRepository;
use Mockery\Adapter\Phpunit\MockeryTestCase;
use Mockery as m;
use Symfony\Component\Console\Tester\CommandTester;

class ExportCommandTest extends MockeryTestCase
{
    private OrganizationRepository|m\MockInterface $orgRepository;
    private string $tempDir;

    protected function setUp(): void
    {
        $this->orgRepository = m::mock(OrganizationRepository::class);
        $this->tempDir = sys_get_temp_dir() . '/export_test_' . uniqid();
        mkdir($this->tempDir);
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        array_map('unlink', glob($this->tempDir . '/*'));

        if (is_dir($this->tempDir)) {
            rmdir($this->tempDir);
        }
    }

    public function testFailsWhenNoOrganizationExists(): void
    {
        $this->orgRepository
            ->shouldReceive('findOne')
            ->andReturn(null);

        $tester = $this->executeCommand($this->tempDir . '/export.json');

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('No organization found', $tester->getDisplay());
    }

    private function executeCommand(string $path): CommandTester
    {
        $command = new ExportCommand($this->orgRepository);
        $tester = new CommandTester($command);
        $tester->execute(['path' => $path]);

        return $tester;
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `composer run-script test -- --filter=ExportCommandTest`
Expected: FAIL — class `ExportCommand` does not exist

- [ ] **Step 3: Write minimal ExportCommand skeleton**

Create `src/Command/ExportCommand.php`:

```php
<?php

namespace App\Command;

use App\Repository\OrganizationRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:export',
    description: 'Export organization configuration to a JSON file',
)]
class ExportCommand extends Command
{
    public function __construct(
        private readonly OrganizationRepository $orgRepository,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('path', InputArgument::REQUIRED, 'Path to write the export JSON file');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $organization = $this->orgRepository->findOne();

        if ($organization === null) {
            $io->error('No organization found. Nothing to export.');

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `composer run-script test -- --filter=ExportCommandTest`
Expected: PASS

- [ ] **Step 5: Run code style check**

Run: `composer run-script test && composer run-script cs`
Fix any violations with `composer run-script cs-fix` if needed.

- [ ] **Step 6: Commit**

```bash
git add src/Command/ExportCommand.php tests/Command/ExportCommandTest.php
git commit -m "feat: add ExportCommand skeleton with no-org error path"
```

### Task 2: ExportCommand — full export with JSON output

**Files:**
- Modify: `src/Command/ExportCommand.php`
- Modify: `tests/Command/ExportCommandTest.php`

- [ ] **Step 1: Write the failing test for successful export**

Add to `ExportCommandTest.php` — new test method and updated setUp/helper:

```php
use App\Entity\ManualContact;
use App\Entity\Organization;
use App\Entity\ProviderCredential;
use App\Entity\SyncList;
use Symfony\Component\Uid\Uuid;

// Add to setUp():
// (no changes needed — orgRepository already mocked)

public function testExportsAllEntitiesAsJson(): void
{
    $org = new Organization();
    $org->setName('Test Org');
    $org->setRetentionDays(30);

    $cred1 = new ProviderCredential();
    $cred1->setOrganization($org);
    $cred1->setProviderName('planning_center');
    $cred1->setLabel('PC');
    $cred1->setCredentialsArray(['app_id' => 'id123', 'app_secret' => 'secret456']);
    $cred1->setMetadata(['refresh_at' => '2026-01-01']);

    $cred2 = new ProviderCredential();
    $cred2->setOrganization($org);
    $cred2->setProviderName('google_groups');
    $cred2->setLabel('Google');
    $cred2->setCredentialsArray(['token' => 'abc']);

    $contact1 = new ManualContact();
    $contact1->setOrganization($org);
    $contact1->setName('John Doe');
    $contact1->setEmail('john@example.org');

    $list1 = new SyncList();
    $list1->setOrganization($org);
    $list1->setName('test-list');
    $list1->setSourceCredential($cred1);
    $list1->setSourceListIdentifier('source-id');
    $list1->setDestinationCredential($cred2);
    $list1->setDestinationListIdentifier('dest-id');
    $list1->setIsEnabled(true);
    $list1->setCronExpression('0 2 * * *');
    $list1->addManualContact($contact1);

    $org->addProviderCredential($cred1);
    $org->addProviderCredential($cred2);
    $org->addManualContact($contact1);
    $org->addSyncList($list1);

    $this->orgRepository
        ->shouldReceive('findOne')
        ->andReturn($org);

    $exportPath = $this->tempDir . '/export.json';
    $tester = $this->executeCommand($exportPath);

    self::assertSame(0, $tester->getStatusCode());
    self::assertFileExists($exportPath);

    // Verify file permissions (owner read/write only)
    self::assertSame('0600', substr(sprintf('%o', fileperms($exportPath)), -4));

    $data = json_decode(file_get_contents($exportPath), true, 512, JSON_THROW_ON_ERROR);

    self::assertSame(1, $data['version']);
    self::assertArrayHasKey('exportedAt', $data);

    // Organization
    self::assertSame('Test Org', $data['organization']['name']);
    self::assertSame(30, $data['organization']['retentionDays']);
    self::assertSame((string) $org->getId(), $data['organization']['id']);

    // Credentials (decrypted)
    self::assertCount(2, $data['providerCredentials']);
    $pcCred = $this->findByKey($data['providerCredentials'], 'providerName', 'planning_center');
    self::assertSame('id123', $pcCred['credentials']['app_id']);
    self::assertSame('secret456', $pcCred['credentials']['app_secret']);
    self::assertSame(['refresh_at' => '2026-01-01'], $pcCred['metadata']);
    self::assertSame((string) $cred1->getId(), $pcCred['id']);

    // Manual contacts
    self::assertCount(1, $data['manualContacts']);
    self::assertSame('John Doe', $data['manualContacts'][0]['name']);
    self::assertSame('john@example.org', $data['manualContacts'][0]['email']);

    // Sync lists
    self::assertCount(1, $data['syncLists']);
    $list = $data['syncLists'][0];
    self::assertSame('test-list', $list['name']);
    self::assertSame((string) $cred1->getId(), $list['sourceCredentialId']);
    self::assertSame((string) $cred2->getId(), $list['destinationCredentialId']);
    self::assertSame('source-id', $list['sourceListIdentifier']);
    self::assertSame('dest-id', $list['destinationListIdentifier']);
    self::assertTrue($list['isEnabled']);
    self::assertSame('0 2 * * *', $list['cronExpression']);
    self::assertSame([(string) $contact1->getId()], $list['manualContactIds']);

    // Verify output message
    self::assertStringContainsString('export.json', $tester->getDisplay());
}

private function findByKey(array $items, string $key, string $value): ?array
{
    foreach ($items as $item) {
        if ($item[$key] === $value) {
            return $item;
        }
    }

    return null;
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `composer run-script test -- --filter=ExportCommandTest::testExportsAllEntitiesAsJson`
Expected: FAIL — export file not written

- [ ] **Step 3: Implement the full export logic**

Update `ExportCommand::execute()` to build and write the JSON:

```php
protected function execute(InputInterface $input, OutputInterface $output): int
{
    $io = new SymfonyStyle($input, $output);
    $path = $input->getArgument('path');

    $organization = $this->orgRepository->findOne();

    if ($organization === null) {
        $io->error('No organization found. Nothing to export.');

        return Command::FAILURE;
    }

    $data = [
        'version' => 1,
        'exportedAt' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
        'organization' => [
            'id' => (string) $organization->getId(),
            'name' => $organization->getName(),
            'retentionDays' => $organization->getRetentionDays(),
        ],
        'providerCredentials' => $this->exportCredentials($organization),
        'syncLists' => $this->exportSyncLists($organization),
        'manualContacts' => $this->exportManualContacts($organization),
    ];

    $json = json_encode($data, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

    if (file_put_contents($path, $json) === false) {
        $io->error(sprintf('Failed to write export file: %s', $path));

        return Command::FAILURE;
    }

    chmod($path, 0600);

    $io->success(sprintf(
        'Exported to %s: %d credential(s), %d list(s), %d manual contact(s)',
        $path,
        count($data['providerCredentials']),
        count($data['syncLists']),
        count($data['manualContacts']),
    ));

    return Command::SUCCESS;
}

/**
 * @return list<array<string, mixed>>
 */
private function exportCredentials(Organization $organization): array
{
    $result = [];

    foreach ($organization->getProviderCredentials() as $credential) {
        $result[] = [
            'id' => (string) $credential->getId(),
            'providerName' => $credential->getProviderName(),
            'label' => $credential->getLabel(),
            'credentials' => $credential->getCredentialsArray(),
            'metadata' => $credential->getMetadata(),
        ];
    }

    return $result;
}

/**
 * @return list<array<string, mixed>>
 */
private function exportSyncLists(Organization $organization): array
{
    $result = [];

    foreach ($organization->getSyncLists() as $syncList) {
        $manualContactIds = [];

        foreach ($syncList->getManualContacts() as $contact) {
            $manualContactIds[] = (string) $contact->getId();
        }

        $result[] = [
            'id' => (string) $syncList->getId(),
            'name' => $syncList->getName(),
            'sourceCredentialId' => $syncList->getSourceCredential() !== null
                ? (string) $syncList->getSourceCredential()->getId()
                : null,
            'sourceListIdentifier' => $syncList->getSourceListIdentifier(),
            'destinationCredentialId' => $syncList->getDestinationCredential() !== null
                ? (string) $syncList->getDestinationCredential()->getId()
                : null,
            'destinationListIdentifier' => $syncList->getDestinationListIdentifier(),
            'isEnabled' => $syncList->isEnabled(),
            'cronExpression' => $syncList->getCronExpression(),
            'manualContactIds' => $manualContactIds,
        ];
    }

    return $result;
}

/**
 * @return list<array<string, mixed>>
 */
private function exportManualContacts(Organization $organization): array
{
    $result = [];

    foreach ($organization->getManualContacts() as $contact) {
        $result[] = [
            'id' => (string) $contact->getId(),
            'name' => $contact->getName(),
            'email' => $contact->getEmail(),
        ];
    }

    return $result;
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `composer run-script test -- --filter=ExportCommandTest`
Expected: PASS

- [ ] **Step 5: Run code style check**

Run: `composer run-script test && composer run-script cs`

- [ ] **Step 6: Commit**

```bash
git add src/Command/ExportCommand.php tests/Command/ExportCommandTest.php
git commit -m "feat: implement full export with JSON output and file permissions"
```

---

## Chunk 2: Import Command

### Task 3: ImportCommand — file validation error paths

**Files:**
- Create: `src/Command/ImportCommand.php`
- Create: `tests/Command/ImportCommandTest.php`

- [ ] **Step 1: Write the failing tests for validation errors**

Create `tests/Command/ImportCommandTest.php`:

```php
<?php

namespace App\Tests\Command;

use App\Command\ImportCommand;
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
        $this->tempDir = sys_get_temp_dir() . '/import_test_' . uniqid();
        mkdir($this->tempDir);
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        array_map('unlink', glob($this->tempDir . '/*'));

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
        $path = $this->tempDir . '/bad.json';
        file_put_contents($path, 'not json at all');

        $tester = $this->executeCommand($path);

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('Invalid JSON', $tester->getDisplay());
    }

    public function testFailsWhenVersionIsUnsupported(): void
    {
        $path = $this->tempDir . '/future.json';
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
        $path = $this->tempDir . '/incomplete.json';
        file_put_contents($path, json_encode(['version' => 1]));

        $tester = $this->executeCommand($path);

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('Missing required', $tester->getDisplay());
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
```

- [ ] **Step 2: Run test to verify it fails**

Run: `composer run-script test -- --filter=ImportCommandTest`
Expected: FAIL — class `ImportCommand` does not exist

- [ ] **Step 3: Write minimal ImportCommand with validation**

Create `src/Command/ImportCommand.php`:

```php
<?php

namespace App\Command;

use App\Repository\OrganizationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:import',
    description: 'Import organization configuration from a JSON file',
)]
class ImportCommand extends Command
{
    public function __construct(
        private readonly OrganizationRepository $orgRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('path', InputArgument::REQUIRED, 'Path to the import JSON file');
        $this->addOption('force', null, InputOption::VALUE_NONE, 'Skip confirmation prompt when existing data will be overwritten');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $path = $input->getArgument('path');

        if (!file_exists($path)) {
            $io->error(sprintf('File not found: %s', $path));

            return Command::FAILURE;
        }

        $contents = file_get_contents($path);

        try {
            $data = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $io->error('Invalid JSON in import file.');

            return Command::FAILURE;
        }

        $version = $data['version'] ?? null;

        if ($version !== 1) {
            $io->error(sprintf('Unsupported version: %s. This command supports version 1.', $version ?? 'missing'));

            return Command::FAILURE;
        }

        $requiredKeys = ['organization', 'providerCredentials', 'syncLists', 'manualContacts'];
        $missingKeys = array_diff($requiredKeys, array_keys($data));

        if ($missingKeys !== []) {
            $io->error(sprintf('Missing required keys: %s', implode(', ', $missingKeys)));

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `composer run-script test -- --filter=ImportCommandTest`
Expected: PASS

- [ ] **Step 5: Run code style check**

Run: `composer run-script test && composer run-script cs`

- [ ] **Step 6: Commit**

```bash
git add src/Command/ImportCommand.php tests/Command/ImportCommandTest.php
git commit -m "feat: add ImportCommand skeleton with file validation"
```

### Task 4: ImportCommand — existing data confirmation and abort paths

**Files:**
- Modify: `src/Command/ImportCommand.php`
- Modify: `tests/Command/ImportCommandTest.php`

- [ ] **Step 1: Write the failing tests**

Add to `ImportCommandTest.php`:

```php
public function testAbortsInNonInteractiveModeWhenDataExists(): void
{
    $path = $this->writeValidExportFile();

    $this->orgRepository
        ->shouldReceive('findOne')
        ->andReturn(m::mock(\App\Entity\Organization::class));

    $tester = $this->executeCommand($path, interactive: false);

    self::assertSame(1, $tester->getStatusCode());
    self::assertStringContainsString('already exists', $tester->getDisplay());
}

public function testAbortsWhenUserDeclinesConfirmation(): void
{
    $path = $this->writeValidExportFile();

    $this->orgRepository
        ->shouldReceive('findOne')
        ->andReturn(m::mock(\App\Entity\Organization::class));

    $tester = $this->executeCommand($path, interactive: true, inputs: ['no']);

    self::assertSame(2, $tester->getStatusCode());
    self::assertStringContainsString('aborted', $tester->getDisplay());
}

private function writeValidExportFile(): string
{
    $path = $this->tempDir . '/valid.json';
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
```

- [ ] **Step 2: Run test to verify it fails**

Run: `composer run-script test -- --filter=ImportCommandTest::testAbortsInNonInteractiveModeWhenDataExists`
Expected: FAIL — command returns 0 instead of 1

- [ ] **Step 3: Add existing-data check to ImportCommand**

Add after the missing keys check in `execute()`:

```php
$existingOrg = $this->orgRepository->findOne();

if ($existingOrg !== null) {
    if (!$input->getOption('force')) {
        if (!$input->isInteractive()) {
            $io->error('Existing data found. Use --force to overwrite in non-interactive mode.');

            return Command::FAILURE;
        }

        $confirm = $io->confirm(
            'Existing data will be permanently deleted and replaced. Continue?',
            false,
        );

        if (!$confirm) {
            $io->warning('Import aborted.');

            return self::ABORT;
        }
    }
}
```

Add a class constant for the abort exit code:

```php
private const ABORT = 2;
```

- [ ] **Step 4: Run test to verify it passes**

Run: `composer run-script test -- --filter=ImportCommandTest`
Expected: PASS

- [ ] **Step 5: Run code style check**

Run: `composer run-script test && composer run-script cs`

- [ ] **Step 6: Commit**

```bash
git add src/Command/ImportCommand.php tests/Command/ImportCommandTest.php
git commit -m "feat: add existing-data confirmation and abort paths to ImportCommand"
```

### Task 5: ImportCommand — wipe and import (happy path)

**Files:**
- Modify: `src/Command/ImportCommand.php`
- Modify: `tests/Command/ImportCommandTest.php`

- [ ] **Step 1: Write the failing test for full import**

Add to `ImportCommandTest.php`:

```php
use App\Entity\ManualContact;
use App\Entity\Organization;
use App\Entity\ProviderCredential;
use App\Entity\SyncList;

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
    $path = $this->tempDir . '/full.json';
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
```

- [ ] **Step 2: Run test to verify it fails**

Run: `composer run-script test -- --filter=ImportCommandTest::testImportsAllEntitiesFromJson`
Expected: FAIL — command returns 0 but persists nothing

- [ ] **Step 3: Implement the full import logic**

Add these methods to `ImportCommand.php`:

```php
use App\Entity\ManualContact;
use App\Entity\Organization;
use App\Entity\ProviderCredential;
use App\Entity\SyncList;
use Symfony\Component\Uid\Uuid;

// Add constant:
private const ABORT = 2;

// Replace the end of execute() (after the existing-data check) with:

$this->entityManager->beginTransaction();

try {
    if ($existingOrg !== null) {
        $this->entityManager->remove($existingOrg);
        $this->entityManager->flush();
    }

    $organization = $this->importOrganization($data['organization']);
    $credentialMap = $this->importCredentials($data['providerCredentials'], $organization);
    $contactMap = $this->importManualContacts($data['manualContacts'], $organization);
    $this->importSyncLists($data['syncLists'], $organization, $credentialMap, $contactMap);

    $this->entityManager->flush();
    $this->entityManager->commit();
} catch (\Throwable $e) {
    $this->entityManager->rollback();
    $io->error('Import failed: ' . $e->getMessage());

    return Command::FAILURE;
}

$io->success(sprintf(
    'Imported: %d credential(s), %d list(s), %d manual contact(s)',
    count($data['providerCredentials']),
    count($data['syncLists']),
    count($data['manualContacts']),
));

return Command::SUCCESS;
```

Add the private import methods:

```php
private function setEntityId(object $entity, string $uuid): void
{
    $reflection = new \ReflectionProperty($entity, 'id');
    $reflection->setValue($entity, Uuid::fromString($uuid));
}

private function importOrganization(array $data): Organization
{
    $organization = new Organization();
    $this->setEntityId($organization, $data['id']);
    $organization->setName($data['name']);
    $organization->setRetentionDays($data['retentionDays'] ?? null);

    $this->entityManager->persist($organization);

    return $organization;
}

/**
 * @return array<string, ProviderCredential> UUID → entity map
 */
private function importCredentials(array $credentials, Organization $organization): array
{
    $map = [];

    foreach ($credentials as $credData) {
        $credential = new ProviderCredential();
        $this->setEntityId($credential, $credData['id']);
        $credential->setOrganization($organization);
        $credential->setProviderName($credData['providerName']);
        $credential->setLabel($credData['label'] ?? null);
        $credential->setCredentialsArray($credData['credentials']);
        $credential->setMetadata($credData['metadata'] ?? null);

        $this->entityManager->persist($credential);
        $map[$credData['id']] = $credential;
    }

    return $map;
}

/**
 * @return array<string, ManualContact> UUID → entity map
 */
private function importManualContacts(array $contacts, Organization $organization): array
{
    $map = [];

    foreach ($contacts as $contactData) {
        $contact = new ManualContact();
        $this->setEntityId($contact, $contactData['id']);
        $contact->setOrganization($organization);
        $contact->setName($contactData['name']);
        $contact->setEmail($contactData['email']);

        $this->entityManager->persist($contact);
        $map[$contactData['id']] = $contact;
    }

    return $map;
}

/**
 * @param array<string, ProviderCredential> $credentialMap
 * @param array<string, ManualContact>      $contactMap
 */
private function importSyncLists(
    array $syncLists,
    Organization $organization,
    array $credentialMap,
    array $contactMap,
): void {
    foreach ($syncLists as $listData) {
        $syncList = new SyncList();
        $this->setEntityId($syncList, $listData['id']);
        $syncList->setOrganization($organization);
        $syncList->setName($listData['name']);
        $syncList->setIsEnabled($listData['isEnabled']);
        $syncList->setCronExpression($listData['cronExpression'] ?? null);
        $syncList->setSourceListIdentifier($listData['sourceListIdentifier'] ?? null);
        $syncList->setDestinationListIdentifier($listData['destinationListIdentifier'] ?? null);

        if (isset($listData['sourceCredentialId'])) {
            if (!isset($credentialMap[$listData['sourceCredentialId']])) {
                throw new \RuntimeException(sprintf(
                    'SyncList "%s" references unknown source credential: %s',
                    $listData['name'],
                    $listData['sourceCredentialId'],
                ));
            }
            $syncList->setSourceCredential($credentialMap[$listData['sourceCredentialId']]);
        }

        if (isset($listData['destinationCredentialId'])) {
            if (!isset($credentialMap[$listData['destinationCredentialId']])) {
                throw new \RuntimeException(sprintf(
                    'SyncList "%s" references unknown destination credential: %s',
                    $listData['name'],
                    $listData['destinationCredentialId'],
                ));
            }
            $syncList->setDestinationCredential($credentialMap[$listData['destinationCredentialId']]);
        }

        foreach ($listData['manualContactIds'] ?? [] as $contactId) {
            if (!isset($contactMap[$contactId])) {
                throw new \RuntimeException(sprintf(
                    'SyncList "%s" references unknown manual contact: %s',
                    $listData['name'],
                    $contactId,
                ));
            }
            $syncList->addManualContact($contactMap[$contactId]);
        }

        $this->entityManager->persist($syncList);
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `composer run-script test -- --filter=ImportCommandTest`
Expected: PASS

- [ ] **Step 5: Run code style check**

Run: `composer run-script test && composer run-script cs`

- [ ] **Step 6: Commit**

```bash
git add src/Command/ImportCommand.php tests/Command/ImportCommandTest.php
git commit -m "feat: implement full import with wipe-and-replace, UUID preservation, and credential re-encryption"
```

---

## Chunk 3: Test Evaluation and Cleanup

### Task 6: Evaluate test coverage and fill gaps

**Files:**
- Modify: `tests/Command/ExportCommandTest.php`
- Modify: `tests/Command/ImportCommandTest.php`

- [ ] **Step 1: Evaluate tests against the checklist**

Review existing tests and ask:
1. Are there high-value cases we're missing?
2. Can tests be simplified or consolidated?
3. Are there low-value tests to remove?

High-value cases to consider adding:
- **Export with null credential references** — SyncList with no source/destination credential
- **Import with empty arrays** — no credentials, no lists, no contacts (org-only import)
- **Import with interactive confirmation (user confirms)** — existing data + user says yes

- [ ] **Step 2: Add missing high-value test cases**

Add to `ExportCommandTest.php`:

```php
public function testExportsListWithNullCredentials(): void
{
    $org = new Organization();
    $org->setName('Minimal Org');

    $list = new SyncList();
    $list->setOrganization($org);
    $list->setName('no-creds-list');
    $list->setIsEnabled(false);
    $org->addSyncList($list);

    $this->orgRepository
        ->shouldReceive('findOne')
        ->andReturn($org);

    $exportPath = $this->tempDir . '/export.json';
    $tester = $this->executeCommand($exportPath);

    self::assertSame(0, $tester->getStatusCode());

    $data = json_decode(file_get_contents($exportPath), true, 512, JSON_THROW_ON_ERROR);
    $listData = $data['syncLists'][0];

    self::assertNull($listData['sourceCredentialId']);
    self::assertNull($listData['destinationCredentialId']);
    self::assertFalse($listData['isEnabled']);
    self::assertSame([], $listData['manualContactIds']);
}
```

Add to `ImportCommandTest.php`:

```php
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

public function testFailsOnUnresolvableCredentialReference(): void
{
    $path = $this->tempDir . '/bad-ref.json';
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
    self::assertStringContainsString('unknown source credential', $tester->getDisplay());
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
```

- [ ] **Step 3: Run all tests**

Run: `composer run-script test && composer run-script cs`
Expected: all PASS

- [ ] **Step 4: Commit**

```bash
git add tests/Command/ExportCommandTest.php tests/Command/ImportCommandTest.php
git commit -m "test: add edge-case coverage for export/import commands"
```

### Task 7: Update Command README

**Files:**
- Modify: `src/Command/README.md`

- [ ] **Step 1: Add documentation for the new commands**

Add a section to `src/Command/README.md` documenting `app:export` and `app:import` with usage examples, flags, and security note about plaintext credentials in the export file.

- [ ] **Step 2: Commit**

```bash
git add src/Command/README.md
git commit -m "docs: document app:export and app:import commands"
```
