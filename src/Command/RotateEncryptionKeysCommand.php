<?php

namespace App\Command;

use App\Attribute\Encrypted;
use App\Security\EncryptionService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:rotate-encryption-keys',
    description: 'Re-encrypts all encrypted entity fields using the current encryption key.',
)]
class RotateEncryptionKeysCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly EncryptionService $encryptionService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show what would be rotated without making changes.')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Skip the confirmation prompt.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = $input->getOption('dry-run');
        $force = $input->getOption('force');

        $io->title('Encryption Key Rotation');
        $io->text(sprintf('Current key version: %d', $this->encryptionService->getCurrentVersion()));

        if ($dryRun) {
            $io->note('Dry run mode — no changes will be made.');
        }

        // Discover all entity classes with #[Encrypted] properties
        $entityClasses = $this->discoverEncryptedEntities();

        if ($entityClasses === []) {
            $io->success('No entities with #[Encrypted] fields found.');

            return Command::SUCCESS;
        }

        $io->text(sprintf('Found %d entity class(es) with encrypted fields:', count($entityClasses)));

        foreach ($entityClasses as $className => $properties) {
            $io->text(sprintf('  • %s (%s)', $className, implode(', ', array_map(
                static fn (\ReflectionProperty $p) => $p->getName(),
                $properties,
            ))));
        }

        $io->newLine();

        if (!$dryRun && !$force) {
            $confirmed = $io->confirm('This will re-encrypt all encrypted fields with the current key. Continue?', false);

            if (!$confirmed) {
                $io->warning('Aborted.');

                return Command::SUCCESS;
            }
        }

        $totalEntities = 0;
        $totalFields = 0;
        $rotatedFields = 0;

        $this->entityManager->beginTransaction();

        try {
            foreach ($entityClasses as $className => $properties) {
                $entities = $this->entityManager->getRepository($className)->findAll();
                $io->text(sprintf('Processing %d %s entities...', count($entities), $this->shortClassName($className)));

                foreach ($entities as $entity) {
                    ++$totalEntities;
                    $entityRotated = false;

                    foreach ($properties as $property) {
                        ++$totalFields;
                        $rawValue = $this->getRawColumnValue($entity, $property);

                        if ($rawValue === null || $rawValue === '') {
                            continue;
                        }

                        // Check if already encrypted with the current key version
                        if ($this->encryptionService->isCurrentVersion($rawValue)) {
                            continue;
                        }

                        if (!$dryRun) {
                            // Decrypt with the old key, re-encrypt with the current key.
                            // The entity's in-memory value is already decrypted by the
                            // Doctrine postLoad listener, so we can read the plaintext
                            // directly and re-encrypt it.
                            $plaintext = $property->getValue($entity);
                            $reEncrypted = $this->encryptionService->encrypt($plaintext);

                            // Write the re-encrypted value directly to the property
                            // so the prePersist/preUpdate listener doesn't double-encrypt.
                            $property->setValue($entity, $reEncrypted);
                        }

                        ++$rotatedFields;
                        $entityRotated = true;

                        if ($output->isVerbose()) {
                            $io->text(sprintf(
                                '    Rotating %s::%s (entity %s)',
                                $this->shortClassName($className),
                                $property->getName(),
                                method_exists($entity, 'getId') ? $entity->getId() : '?',
                            ));
                        }
                    }

                    if ($entityRotated && !$dryRun) {
                        $this->entityManager->flush();

                        // Detach and clear to free memory on large datasets
                        $this->entityManager->detach($entity);
                    }
                }
            }

            if (!$dryRun) {
                $this->entityManager->commit();
            } else {
                $this->entityManager->rollback();
            }
        } catch (\Throwable $e) {
            $this->entityManager->rollback();
            $io->error(sprintf('Key rotation failed: %s', $e->getMessage()));

            return Command::FAILURE;
        }

        $io->newLine();

        if ($dryRun) {
            $io->success(sprintf(
                'Dry run complete. %d field(s) across %d entity/entities would be rotated.',
                $rotatedFields,
                $totalEntities,
            ));
        } else {
            $io->success(sprintf(
                'Key rotation complete. %d field(s) re-encrypted to version %d.',
                $rotatedFields,
                $this->encryptionService->getCurrentVersion(),
            ));
        }

        return Command::SUCCESS;
    }

    /**
     * Discovers all Doctrine entity classes that have properties marked with #[Encrypted].
     *
     * @return array<class-string, \ReflectionProperty[]>
     */
    private function discoverEncryptedEntities(): array
    {
        $result = [];
        $allMetadata = $this->entityManager->getMetadataFactory()->getAllMetadata();

        foreach ($allMetadata as $metadata) {
            $className = $metadata->getName();
            $reflection = new \ReflectionClass($className);
            $encryptedProperties = [];

            foreach ($reflection->getProperties() as $property) {
                if ($property->getAttributes(Encrypted::class) !== []) {
                    $encryptedProperties[] = $property;
                }
            }

            if ($encryptedProperties !== []) {
                $result[$className] = $encryptedProperties;
            }
        }

        return $result;
    }

    /**
     * Gets the raw database column value for a property, bypassing the Doctrine
     * postLoad decryption. We use the UnitOfWork's original entity data.
     */
    private function getRawColumnValue(object $entity, \ReflectionProperty $property): ?string
    {
        $uow = $this->entityManager->getUnitOfWork();
        $originalData = $uow->getOriginalEntityData($entity);
        $fieldName = $property->getName();

        return $originalData[$fieldName] ?? null;
    }

    private function shortClassName(string $className): string
    {
        $parts = explode('\\', $className);

        return end($parts);
    }
}
