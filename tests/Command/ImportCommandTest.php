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
