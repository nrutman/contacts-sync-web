<?php

namespace App\Tests\Command;

use App\Command\ExportCommand;
use App\Entity\ManualContact;
use App\Entity\Organization;
use App\Entity\ProviderCredential;
use App\Entity\SyncList;
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
        $this->tempDir = sys_get_temp_dir().'/export_test_'.uniqid();
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

    public function testFailsWhenNoOrganizationExists(): void
    {
        $this->orgRepository
            ->shouldReceive('findOne')
            ->andReturn(null);

        $tester = $this->executeCommand($this->tempDir.'/export.json');

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('No organization found', $tester->getDisplay());
    }

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

        $exportPath = $this->tempDir.'/export.json';
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

    private function executeCommand(string $path): CommandTester
    {
        $command = new ExportCommand($this->orgRepository);
        $tester = new CommandTester($command);
        $tester->execute(['path' => $path]);

        return $tester;
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
}
