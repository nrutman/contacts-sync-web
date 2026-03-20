<?php

namespace App\Tests\Command;

use App\Command\RotateEncryptionKeysCommand;
use App\Doctrine\Type\EncryptedType;
use App\Security\EncryptionService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\ClassMetadataFactory;
use Doctrine\ORM\Mapping\FieldMapping;
use Mockery\Adapter\Phpunit\MockeryTestCase;
use Mockery as m;
use Symfony\Component\Console\Tester\CommandTester;

class RotateEncryptionKeysCommandTest extends MockeryTestCase
{
    private EntityManagerInterface|m\LegacyMockInterface $entityManager;
    private EncryptionService|m\LegacyMockInterface $encryptionService;
    private ClassMetadataFactory|m\LegacyMockInterface $metadataFactory;

    protected function setUp(): void
    {
        $this->entityManager = m::mock(EntityManagerInterface::class);
        $this->encryptionService = m::mock(EncryptionService::class);
        $this->metadataFactory = m::mock(ClassMetadataFactory::class);

        $this->entityManager
            ->shouldReceive('getMetadataFactory')
            ->andReturn($this->metadataFactory);

        $this->encryptionService
            ->shouldReceive('getCurrentVersion')
            ->andReturn(2)
            ->byDefault();
    }

    public function testDryRunWithNoEncryptedEntities(): void
    {
        $this->metadataFactory
            ->shouldReceive('getAllMetadata')
            ->once()
            ->andReturn([]);

        $command = new RotateEncryptionKeysCommand(
            $this->entityManager,
            $this->encryptionService,
        );

        $tester = new CommandTester($command);
        $tester->execute(['--dry-run' => true]);

        $tester->assertCommandIsSuccessful();
        $this->assertStringContainsString('No entities with encrypted fields found', $tester->getDisplay());
    }

    public function testDryRunDiscoverEncryptedEntities(): void
    {
        $metadata = $this->createMetadataWithEncryptedField('App\Entity\ProviderCredential', 'credentials');

        $this->metadataFactory
            ->shouldReceive('getAllMetadata')
            ->once()
            ->andReturn([$metadata]);

        $repository = m::mock(\Doctrine\ORM\EntityRepository::class);
        $repository->shouldReceive('findAll')
            ->once()
            ->andReturn([]);

        $this->entityManager
            ->shouldReceive('getRepository')
            ->with('App\Entity\ProviderCredential')
            ->andReturn($repository);

        $this->entityManager
            ->shouldReceive('beginTransaction')
            ->once();

        $this->entityManager
            ->shouldReceive('rollback')
            ->once();

        $command = new RotateEncryptionKeysCommand(
            $this->entityManager,
            $this->encryptionService,
        );

        $tester = new CommandTester($command);
        $tester->execute(['--dry-run' => true]);

        $tester->assertCommandIsSuccessful();
        $display = $tester->getDisplay();
        $this->assertStringContainsString('1 entity class(es)', $display);
        $this->assertStringContainsString('ProviderCredential', $display);
        $this->assertStringContainsString('credentials', $display);
        $this->assertStringContainsString('Dry run complete', $display);
    }

    public function testAbortWhenNotConfirmed(): void
    {
        $metadata = $this->createMetadataWithEncryptedField('App\Entity\ProviderCredential', 'credentials');

        $this->metadataFactory
            ->shouldReceive('getAllMetadata')
            ->once()
            ->andReturn([$metadata]);

        $command = new RotateEncryptionKeysCommand(
            $this->entityManager,
            $this->encryptionService,
        );

        $tester = new CommandTester($command);
        $tester->setInputs(['no']);
        $tester->execute([]);

        $tester->assertCommandIsSuccessful();
        $this->assertStringContainsString('Aborted', $tester->getDisplay());
    }

    public function testForceSkipsConfirmationPrompt(): void
    {
        $metadata = $this->createMetadataWithEncryptedField('App\Entity\ProviderCredential', 'credentials');

        $this->metadataFactory
            ->shouldReceive('getAllMetadata')
            ->once()
            ->andReturn([$metadata]);

        $repository = m::mock(\Doctrine\ORM\EntityRepository::class);
        $repository->shouldReceive('findAll')
            ->once()
            ->andReturn([]);

        $this->entityManager
            ->shouldReceive('getRepository')
            ->with('App\Entity\ProviderCredential')
            ->andReturn($repository);

        $this->entityManager
            ->shouldReceive('beginTransaction')
            ->once();

        $this->entityManager
            ->shouldReceive('commit')
            ->once();

        $command = new RotateEncryptionKeysCommand(
            $this->entityManager,
            $this->encryptionService,
        );

        $tester = new CommandTester($command);
        $tester->execute(['--force' => true]);

        $tester->assertCommandIsSuccessful();
        $display = $tester->getDisplay();
        $this->assertStringContainsString('Key rotation complete', $display);
        $this->assertStringContainsString('re-encrypted to version 2', $display);
    }

    public function testDisplaysCurrentKeyVersion(): void
    {
        $this->encryptionService
            ->shouldReceive('getCurrentVersion')
            ->andReturn(3);

        $this->metadataFactory
            ->shouldReceive('getAllMetadata')
            ->once()
            ->andReturn([]);

        $command = new RotateEncryptionKeysCommand(
            $this->entityManager,
            $this->encryptionService,
        );

        $tester = new CommandTester($command);
        $tester->execute(['--dry-run' => true]);

        $tester->assertCommandIsSuccessful();
        $this->assertStringContainsString('Current key version: 3', $tester->getDisplay());
    }

    public function testSkipsEntityClassesWithoutEncryptedFields(): void
    {
        $metadataWithEncrypted = $this->createMetadataWithEncryptedField('App\Entity\ProviderCredential', 'credentials');

        $metadataWithout = m::mock(ClassMetadata::class);
        $metadataWithout->shouldReceive('getName')
            ->andReturn('App\Entity\SyncList');
        $metadataWithout->fieldMappings = [
            'name' => new FieldMapping(fieldName: 'name', type: 'string', columnName: 'name'),
        ];

        $this->metadataFactory
            ->shouldReceive('getAllMetadata')
            ->once()
            ->andReturn([$metadataWithEncrypted, $metadataWithout]);

        $repository = m::mock(\Doctrine\ORM\EntityRepository::class);
        $repository->shouldReceive('findAll')
            ->once()
            ->andReturn([]);

        $this->entityManager
            ->shouldReceive('getRepository')
            ->with('App\Entity\ProviderCredential')
            ->andReturn($repository);

        // Should NOT call getRepository for SyncList
        $this->entityManager
            ->shouldNotReceive('getRepository')
            ->with('App\Entity\SyncList');

        $this->entityManager
            ->shouldReceive('beginTransaction')
            ->once();

        $this->entityManager
            ->shouldReceive('rollback')
            ->once();

        $command = new RotateEncryptionKeysCommand(
            $this->entityManager,
            $this->encryptionService,
        );

        $tester = new CommandTester($command);
        $tester->execute(['--dry-run' => true]);

        $tester->assertCommandIsSuccessful();
        $display = $tester->getDisplay();
        $this->assertStringContainsString('1 entity class(es)', $display);
        $this->assertStringNotContainsString('SyncList', $display);
    }

    public function testRollsBackOnException(): void
    {
        $metadata = $this->createMetadataWithEncryptedField('App\Entity\ProviderCredential', 'credentials');

        $this->metadataFactory
            ->shouldReceive('getAllMetadata')
            ->once()
            ->andReturn([$metadata]);

        $repository = m::mock(\Doctrine\ORM\EntityRepository::class);
        $repository->shouldReceive('findAll')
            ->once()
            ->andThrow(new \RuntimeException('Database error'));

        $this->entityManager
            ->shouldReceive('getRepository')
            ->with('App\Entity\ProviderCredential')
            ->andReturn($repository);

        $this->entityManager
            ->shouldReceive('beginTransaction')
            ->once();

        $this->entityManager
            ->shouldReceive('rollback')
            ->once();

        $command = new RotateEncryptionKeysCommand(
            $this->entityManager,
            $this->encryptionService,
        );

        $tester = new CommandTester($command);
        $tester->execute(['--force' => true]);

        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString('Key rotation failed', $tester->getDisplay());
        $this->assertStringContainsString('Database error', $tester->getDisplay());
    }

    private function createMetadataWithEncryptedField(string $className, string $fieldName): ClassMetadata|m\LegacyMockInterface
    {
        $metadata = m::mock(ClassMetadata::class);
        $metadata->shouldReceive('getName')
            ->andReturn($className);
        $metadata->fieldMappings = [
            $fieldName => new FieldMapping(fieldName: $fieldName, type: EncryptedType::NAME, columnName: $fieldName),
        ];

        return $metadata;
    }
}
