<?php

namespace App\Command;

use App\Entity\InMemoryContact;
use App\Entity\Organization;
use App\Entity\SyncList;
use App\File\FileProvider;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Filesystem\Exception\FileNotFoundException;

#[AsCommand(
    name: 'app:migrate-config',
    description: 'One-time migration of parameters.yml and Google token into the database',
)]
class MigrateConfigToDbCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly FileProvider $fileProvider,
        private readonly string $planningCenterAppId,
        private readonly string $planningCenterAppSecret,
        private readonly array $googleConfiguration,
        private readonly string $googleDomain,
        private readonly array $lists,
        private readonly array $inMemoryContacts,
        private readonly string $varPath,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Migrate Configuration to Database');

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
            $organization = $this->createOrganization($io);
            $syncListMap = $this->createSyncLists($organization);
            $contactCount = $this->createInMemoryContacts($organization, $syncListMap);

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
            'Google token: '.($organization->getGoogleToken() !== null ? '✓ imported' : '✗ not found — run Google OAuth setup'),
            'Sync lists: '.count($syncListMap).' created',
            'In-memory contacts: '.$contactCount['contacts'].' created (across '.$contactCount['associations'].' list associations)',
        ]);

        $io->note(
            'You can delete config/parameters.yml and var/google-token.json after verifying the migration, '
            .'but keep backups until the web UI is confirmed working.',
        );

        return Command::SUCCESS;
    }

    private function removeExistingData(Organization $organization): void
    {
        $this->entityManager->remove($organization);
        $this->entityManager->flush();
    }

    private function createOrganization(SymfonyStyle $io): Organization
    {
        $organization = new Organization();
        $organization->setName($this->googleDomain);
        $organization->setPlanningCenterAppId($this->planningCenterAppId);
        $organization->setPlanningCenterAppSecret($this->planningCenterAppSecret);
        $organization->setGoogleOAuthCredentials(json_encode($this->googleConfiguration, JSON_THROW_ON_ERROR));
        $organization->setGoogleDomain($this->googleDomain);

        $googleToken = $this->loadGoogleToken($io);
        $organization->setGoogleToken($googleToken);

        $this->entityManager->persist($organization);

        return $organization;
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
    private function createSyncLists(Organization $organization): array
    {
        $map = [];

        foreach ($this->lists as $listName) {
            $syncList = new SyncList();
            $syncList->setOrganization($organization);
            $syncList->setName($listName);
            $syncList->setIsEnabled(true);

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
    private function createInMemoryContacts(Organization $organization, array $syncListMap): array
    {
        $contactCount = 0;
        $associationCount = 0;

        foreach ($this->inMemoryContacts as $name => $config) {
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
