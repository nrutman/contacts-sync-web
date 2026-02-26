<?php

namespace App\Command;

use App\Entity\InMemoryContact;
use App\Entity\Organization;
use App\Entity\ProviderCredential;
use App\Entity\SyncList;
use App\File\FileProvider;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Filesystem\Exception\FileNotFoundException;
use Symfony\Component\Yaml\Yaml;

#[AsCommand(
    name: 'app:migrate-config',
    description: 'One-time migration of parameters.yml and Google token into the database',
)]
class MigrateConfigToDbCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly FileProvider $fileProvider,
        private readonly string $varPath,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('config-file', InputArgument::REQUIRED, 'Path to the legacy parameters.yml file');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Migrate Configuration to Database');

        $configFile = $input->getArgument('config-file');

        if (!file_exists($configFile)) {
            $io->error(sprintf('Configuration file not found: %s', $configFile));

            return Command::FAILURE;
        }

        $parsed = Yaml::parseFile($configFile);
        $params = $parsed['parameters'] ?? [];

        $planningCenterAppId = $params['planning_center.app.id'] ?? '';
        $planningCenterAppSecret = $params['planning_center.app.secret'] ?? '';
        $googleConfiguration = $params['google.authentication'] ?? [];
        $googleDomain = $params['google.domain'] ?? '';
        $lists = $params['lists'] ?? [];
        $inMemoryContacts = $params['contacts'] ?? [];

        if ($this->hasPlaceholderValues($planningCenterAppId, $googleDomain, $lists)) {
            $io->error('The configuration file contains placeholder values. Please fill in real values before migrating.');

            return Command::FAILURE;
        }

        $existingOrg = $this->entityManager->getRepository(Organization::class)->findOneBy([]);

        if ($existingOrg !== null) {
            if (!$input->isInteractive()) {
                $io->error('An organization already exists in the database. Aborting in non-interactive mode.');

                return Command::FAILURE;
            }

            $confirm = $io->confirm(
                'An organization already exists in the database. Do you want to overwrite it?',
                false,
            );

            if (!$confirm) {
                $io->warning('Migration aborted.');

                return Command::SUCCESS;
            }

            $this->removeExistingData($existingOrg);
        }

        $this->entityManager->beginTransaction();

        try {
            $organization = $this->createOrganization($googleDomain);

            $pcCredential = $this->createPlanningCenterCredential(
                $organization,
                $planningCenterAppId,
                $planningCenterAppSecret,
            );

            $googleCredential = $this->createGoogleCredential(
                $io,
                $organization,
                $googleConfiguration,
                $googleDomain,
            );

            $syncListMap = $this->createSyncLists($organization, $lists, $pcCredential, $googleCredential);
            $contactCount = $this->createInMemoryContacts($organization, $syncListMap, $inMemoryContacts);

            $this->entityManager->flush();
            $this->entityManager->commit();
        } catch (\Throwable $e) {
            $this->entityManager->rollback();
            $io->error('Migration failed: '.$e->getMessage());

            return Command::FAILURE;
        }

        $io->success('Migrated configuration to database:');
        $io->listing([
            'Organization: '.$organization->getName(),
            'Planning Center credential: created',
            'Google Groups credential: created'.($googleCredential->getCredentialsArray()['token'] ?? false ? ' (with token)' : ' (no token — run OAuth setup)'),
            'Sync lists: '.count($syncListMap).' created',
            'In-memory contacts: '.$contactCount['contacts'].' created (across '.$contactCount['associations'].' list associations)',
        ]);

        $io->note(
            'You can delete the legacy parameters.yml and var/google-token.json after verifying the migration, '
            .'but keep backups until the web UI is confirmed working.',
        );

        return Command::SUCCESS;
    }

    private function hasPlaceholderValues(string $planningCenterAppId, string $googleDomain, array $lists): bool
    {
        if ($planningCenterAppId === '' || str_starts_with($planningCenterAppId, '{{')) {
            return true;
        }

        if ($googleDomain === '' || str_starts_with($googleDomain, '{{')) {
            return true;
        }

        if ($lists === []) {
            return true;
        }

        return false;
    }

    private function removeExistingData(Organization $organization): void
    {
        $this->entityManager->remove($organization);
        $this->entityManager->flush();
    }

    private function createOrganization(string $name): Organization
    {
        $organization = new Organization();
        $organization->setName($name);

        $this->entityManager->persist($organization);

        return $organization;
    }

    private function createPlanningCenterCredential(
        Organization $organization,
        string $appId,
        string $appSecret,
    ): ProviderCredential {
        $credential = new ProviderCredential();
        $credential->setOrganization($organization);
        $credential->setProviderName('planning_center');
        $credential->setLabel('Planning Center');
        $credential->setCredentialsArray([
            'app_id' => $appId,
            'app_secret' => $appSecret,
        ]);

        $this->entityManager->persist($credential);

        return $credential;
    }

    private function createGoogleCredential(
        SymfonyStyle $io,
        Organization $organization,
        array $googleConfiguration,
        string $googleDomain,
    ): ProviderCredential {
        $credentialsData = [
            'oauth_credentials' => json_encode($googleConfiguration, JSON_THROW_ON_ERROR),
            'domain' => $googleDomain,
        ];

        $googleToken = $this->loadGoogleToken($io);

        if ($googleToken !== null) {
            $credentialsData['token'] = $googleToken;
        }

        $credential = new ProviderCredential();
        $credential->setOrganization($organization);
        $credential->setProviderName('google_groups');
        $credential->setLabel('Google Groups');
        $credential->setCredentialsArray($credentialsData);

        $this->entityManager->persist($credential);

        return $credential;
    }

    private function loadGoogleToken(SymfonyStyle $io): ?string
    {
        $tokenPath = $this->varPath.'/google-token.json';

        try {
            $contents = $this->fileProvider->getContents($tokenPath);
            $io->comment('Found Google token at '.$tokenPath);

            return $contents;
        } catch (FileNotFoundException) {
            $io->comment('No Google token found at '.$tokenPath.' — skipping token import.');

            return null;
        }
    }

    /**
     * @return array<string, SyncList> Map of list name → SyncList entity
     */
    private function createSyncLists(
        Organization $organization,
        array $lists,
        ProviderCredential $sourceCredential,
        ProviderCredential $destinationCredential,
    ): array {
        $map = [];

        foreach ($lists as $listName) {
            $syncList = new SyncList();
            $syncList->setOrganization($organization);
            $syncList->setName($listName);
            $syncList->setIsEnabled(true);
            $syncList->setSourceCredential($sourceCredential);
            $syncList->setSourceListIdentifier($listName);
            $syncList->setDestinationCredential($destinationCredential);
            $syncList->setDestinationListIdentifier($listName);

            $this->entityManager->persist($syncList);

            $map[strtolower($listName)] = $syncList;
        }

        return $map;
    }

    /**
     * @param array<string, SyncList> $syncListMap
     *
     * @return array{contacts: int, associations: int}
     */
    private function createInMemoryContacts(Organization $organization, array $syncListMap, array $inMemoryContacts): array
    {
        $contactCount = 0;
        $associationCount = 0;

        foreach ($inMemoryContacts as $name => $config) {
            $contact = new InMemoryContact();
            $contact->setOrganization($organization);
            $contact->setName((string) $name);
            $contact->setEmail($config['email']);

            $lists = is_array($config['list'])
                ? $config['list']
                : [$config['list']];

            foreach ($lists as $listName) {
                $key = strtolower($listName);

                if (isset($syncListMap[$key])) {
                    $contact->addSyncList($syncListMap[$key]);
                    ++$associationCount;
                }
            }

            $this->entityManager->persist($contact);
            ++$contactCount;
        }

        return ['contacts' => $contactCount, 'associations' => $associationCount];
    }
}
