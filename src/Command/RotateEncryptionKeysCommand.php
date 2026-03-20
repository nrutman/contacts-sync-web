<?php

namespace App\Command;

use App\Doctrine\Type\EncryptedType;
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

        $entityClasses = $this->discoverEncryptedEntities();

        if ($entityClasses === []) {
            $io->success('No entities with encrypted fields found.');

            return Command::SUCCESS;
        }

        $io->text(sprintf('Found %d entity class(es) with encrypted fields:', count($entityClasses)));

        foreach ($entityClasses as $className => $fieldNames) {
            $io->text(sprintf('  • %s (%s)', $this->shortClassName($className), implode(', ', $fieldNames)));
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
            foreach ($entityClasses as $className => $fieldNames) {
                $entities = $this->entityManager->getRepository($className)->findAll();
                $io->text(sprintf('Processing %d %s entities...', count($entities), $this->shortClassName($className)));

                foreach ($entities as $entity) {
                    ++$totalEntities;
                    $entityRotated = false;

                    foreach ($fieldNames as $fieldName) {
                        ++$totalFields;
                        $rawValue = $this->getRawColumnValue($entity, $fieldName);

                        if ($rawValue === null || $rawValue === '') {
                            continue;
                        }

                        if ($this->encryptionService->isCurrentVersion($rawValue)) {
                            continue;
                        }

                        if (!$dryRun) {
                            // The entity's in-memory value is already decrypted by the
                            // EncryptedType DBAL type, so read the plaintext directly
                            // and re-encrypt it.
                            $property = new \ReflectionProperty($entity, $fieldName);
                            $plaintext = $property->getValue($entity);
                            $reEncrypted = $this->encryptionService->encrypt($plaintext);

                            // Write the re-encrypted value directly so the DBAL type
                            // doesn't double-encrypt on flush.
                            $property->setValue($entity, $reEncrypted);
                        }

                        ++$rotatedFields;
                        $entityRotated = true;

                        if ($output->isVerbose()) {
                            $io->text(sprintf(
                                '    Rotating %s::%s (entity %s)',
                                $this->shortClassName($className),
                                $fieldName,
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
     * Discovers all Doctrine entity classes that have fields using the 'encrypted' DBAL type.
     *
     * @return array<class-string, string[]> Map of class name to field names
     */
    private function discoverEncryptedEntities(): array
    {
        $result = [];
        $allMetadata = $this->entityManager->getMetadataFactory()->getAllMetadata();

        foreach ($allMetadata as $metadata) {
            $encryptedFields = [];

            foreach ($metadata->fieldMappings as $fieldMapping) {
                if ($fieldMapping->type === EncryptedType::NAME) {
                    $encryptedFields[] = $fieldMapping->fieldName;
                }
            }

            if ($encryptedFields !== []) {
                $result[$metadata->getName()] = $encryptedFields;
            }
        }

        return $result;
    }

    /**
     * Gets the raw database column value for a field, bypassing the DBAL type
     * decryption. We use the UnitOfWork's original entity data.
     */
    private function getRawColumnValue(object $entity, string $fieldName): ?string
    {
        $uow = $this->entityManager->getUnitOfWork();
        $originalData = $uow->getOriginalEntityData($entity);

        return $originalData[$fieldName] ?? null;
    }

    private function shortClassName(string $className): string
    {
        $parts = explode('\\', $className);

        return end($parts);
    }
}
