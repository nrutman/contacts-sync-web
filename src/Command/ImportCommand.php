<?php

namespace App\Command;

use App\Entity\ManualContact;
use App\Entity\Organization;
use App\Entity\ProviderCredential;
use App\Entity\SyncList;
use App\Repository\OrganizationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Uid\Uuid;

#[AsCommand(
    name: 'app:import',
    description: 'Import organization configuration from a JSON file',
)]
class ImportCommand extends Command
{
    private const ABORT = 2;

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

        $existingOrg = $this->orgRepository->findOne();

        if ($existingOrg !== null) {
            if (!$input->getOption('force')) {
                if (!$input->isInteractive()) {
                    $io->error('Existing data already exists. Use --force to overwrite in non-interactive mode.');

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
            $io->error('Import failed: '.$e->getMessage());

            return Command::FAILURE;
        }

        $io->success(sprintf(
            'Imported: %d credential(s), %d list(s), %d manual contact(s)',
            count($data['providerCredentials']),
            count($data['syncLists']),
            count($data['manualContacts']),
        ));

        return Command::SUCCESS;
    }

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
     * @return array<string, ProviderCredential> UUID -> entity map
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
     * @return array<string, ManualContact> UUID -> entity map
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
     * @param array<string, ManualContact> $contactMap
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
                    throw new \RuntimeException(sprintf('SyncList "%s" references unknown source credential: %s', $listData['name'], $listData['sourceCredentialId']));
                }
                $syncList->setSourceCredential($credentialMap[$listData['sourceCredentialId']]);
            }

            if (isset($listData['destinationCredentialId'])) {
                if (!isset($credentialMap[$listData['destinationCredentialId']])) {
                    throw new \RuntimeException(sprintf('SyncList "%s" references unknown destination credential: %s', $listData['name'], $listData['destinationCredentialId']));
                }
                $syncList->setDestinationCredential($credentialMap[$listData['destinationCredentialId']]);
            }

            foreach ($listData['manualContactIds'] ?? [] as $contactId) {
                if (!isset($contactMap[$contactId])) {
                    throw new \RuntimeException(sprintf('SyncList "%s" references unknown manual contact: %s', $listData['name'], $contactId));
                }
                $syncList->addManualContact($contactMap[$contactId]);
            }

            $this->entityManager->persist($syncList);
        }
    }
}
