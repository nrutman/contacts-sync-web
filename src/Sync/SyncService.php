<?php

namespace App\Sync;

use App\Client\Google\GoogleClient;
use App\Client\Google\GoogleClientFactory;
use App\Client\Google\InvalidGoogleTokenException;
use App\Client\PlanningCenter\PlanningCenterClientFactory;
use App\Contact\Contact;
use App\Contact\ContactListAnalyzer;
use App\Entity\Organization;
use App\Entity\SyncList;
use App\Entity\SyncRun;
use App\Entity\User;
use App\Event\SyncCompletedEvent;
use App\Repository\InMemoryContactRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

class SyncService
{
    public function __construct(
        private readonly GoogleClientFactory $googleClientFactory,
        private readonly PlanningCenterClientFactory $planningCenterClientFactory,
        private readonly InMemoryContactRepository $inMemoryContactRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly EventDispatcherInterface $eventDispatcher,
    ) {
    }

    public function executeSync(
        SyncList $syncList,
        bool $dryRun = false,
        ?User $triggeredBy = null,
        string $trigger = 'manual',
    ): SyncResult {
        $organization = $syncList->getOrganization();
        $listName = $syncList->getName();
        $log = '';

        // Create and persist a SyncRun record
        $syncRun = new SyncRun();
        $syncRun->setSyncList($syncList);
        $syncRun->setTriggeredBy($trigger);
        $syncRun->setTriggeredByUser($triggeredBy);
        $syncRun->setStatus('running');
        $syncRun->setStartedAt(new \DateTimeImmutable());
        $this->entityManager->persist($syncRun);
        $this->entityManager->flush();

        try {
            $log .= $this->logLine(sprintf('Processing list: %s', $listName));

            // Build clients from organization credentials
            $googleClient = $this->initializeGoogleClient($organization);
            $planningCenterClient = $this->planningCenterClientFactory->create(
                $organization,
            );

            // Check if token was refreshed and persist if so
            $this->persistTokenIfRefreshed($organization, $googleClient);

            // Fetch source contacts from Planning Center
            $log .= $this->logLine(
                'Fetching source contacts from Planning Center...',
            );
            $sourceContacts = $planningCenterClient->getContacts($listName);
            $log .= $this->logLine(
                sprintf('  Found %d source contacts', count($sourceContacts)),
            );

            // Merge with in-memory contacts
            $inMemoryContacts = $this->getInMemoryContactDtos($syncList);
            $mergedSourceContacts = $this->mergeLists(
                $sourceContacts,
                $inMemoryContacts,
            );

            if (count($inMemoryContacts) > 0) {
                $log .= $this->logLine(
                    sprintf(
                        '  Merged %d in-memory contacts (total: %d)',
                        count($inMemoryContacts),
                        count($mergedSourceContacts),
                    ),
                );
            }

            // Fetch destination contacts from Google
            $log .= $this->logLine(
                'Fetching destination contacts from Google...',
            );
            $destContacts = $googleClient->getContacts($listName);
            $log .= $this->logLine(
                sprintf(
                    '  Found %d destination contacts',
                    count($destContacts),
                ),
            );

            // Compute diff
            $diff = new ContactListAnalyzer(
                $mergedSourceContacts,
                $destContacts,
            );
            $contactsToAdd = $diff->getContactsToAdd();
            $contactsToRemove = $diff->getContactsToRemove();

            $log .= $this->logLine(
                sprintf(
                    'Diff: %d to add, %d to remove',
                    count($contactsToAdd),
                    count($contactsToRemove),
                ),
            );

            // Apply changes unless dry run
            if (!$dryRun) {
                foreach ($contactsToRemove as $removeIndex => $contact) {
                    $log .= $this->logLine(
                        sprintf(
                            'Removing %s (%d/%d)',
                            $contact->email,
                            $removeIndex + 1,
                            count($contactsToRemove),
                        ),
                    );
                    $googleClient->removeContact($listName, $contact);
                }

                foreach ($contactsToAdd as $addIndex => $contact) {
                    $log .= $this->logLine(
                        sprintf(
                            'Adding %s (%d/%d)',
                            $contact->email,
                            $addIndex + 1,
                            count($contactsToAdd),
                        ),
                    );
                    $googleClient->addContact($listName, $contact);
                }
            } else {
                $log .= $this->logLine('Dry run — no changes applied.');
            }

            $result = new SyncResult(
                sourceCount: count($mergedSourceContacts),
                destinationCount: count($destContacts),
                addedCount: count($contactsToAdd),
                removedCount: count($contactsToRemove),
                log: $log,
                success: true,
            );

            // Update SyncRun with results
            $syncRun->setStatus('success');
            $syncRun->setSourceCount($result->sourceCount);
            $syncRun->setDestinationCount($result->destinationCount);
            $syncRun->setAddedCount($result->addedCount);
            $syncRun->setRemovedCount($result->removedCount);
            $syncRun->setLog($result->log);
            $syncRun->setCompletedAt(new \DateTimeImmutable());
            $this->entityManager->flush();

            $this->eventDispatcher->dispatch(new SyncCompletedEvent($syncRun));

            return $result;
        } catch (\Throwable $e) {
            $log .= $this->logLine(sprintf('ERROR: %s', $e->getMessage()));

            $result = new SyncResult(
                sourceCount: 0,
                destinationCount: 0,
                addedCount: 0,
                removedCount: 0,
                log: $log,
                success: false,
                errorMessage: $e->getMessage(),
            );

            $syncRun->setStatus('failed');
            $syncRun->setLog($result->log);
            $syncRun->setErrorMessage($result->errorMessage);
            $syncRun->setCompletedAt(new \DateTimeImmutable());
            $this->entityManager->flush();

            $this->eventDispatcher->dispatch(new SyncCompletedEvent($syncRun));

            return $result;
        }
    }

    /**
     * Initializes a GoogleClient for the given organization, including token setup.
     *
     * @throws InvalidGoogleTokenException
     */
    private function initializeGoogleClient(
        Organization $organization,
    ): GoogleClient {
        $googleClient = $this->googleClientFactory->create($organization);
        $googleClient->initialize();

        return $googleClient;
    }

    /**
     * If the Google OAuth token was refreshed during initialization, persist the updated token.
     */
    private function persistTokenIfRefreshed(
        Organization $organization,
        GoogleClient $googleClient,
    ): void {
        $currentToken = $googleClient->getTokenData();

        if ($currentToken === null) {
            return;
        }

        $newTokenJson = json_encode($currentToken, JSON_THROW_ON_ERROR);

        if ($newTokenJson !== $organization->getGoogleToken()) {
            $organization->setGoogleToken($newTokenJson);
            $this->entityManager->flush();
        }
    }

    /**
     * Loads InMemoryContact entities for a SyncList and converts them to Contact DTOs.
     *
     * @return Contact[]
     */
    private function getInMemoryContactDtos(SyncList $syncList): array
    {
        $entities = $this->inMemoryContactRepository->findBySyncList($syncList);
        $contacts = [];

        foreach ($entities as $entity) {
            $contact = new Contact();
            $contact->email = $entity->getEmail();
            $contact->firstName = null;
            $contact->lastName = null;

            // Parse name into first/last if possible
            $nameParts = explode(' ', $entity->getName(), 2);
            $contact->firstName = $nameParts[0] ?? null;
            $contact->lastName = $nameParts[1] ?? null;

            $contacts[] = $contact;
        }

        return $contacts;
    }

    /**
     * Merges multiple contact lists, deduplicating by email address.
     *
     * @param Contact[] ...$lists
     *
     * @return Contact[]
     */
    private function mergeLists(array ...$lists): array
    {
        $uniqueContacts = [];

        foreach ($lists as $list) {
            foreach ($list as $contact) {
                if (!isset($uniqueContacts[$contact->email])) {
                    $uniqueContacts[$contact->email] = $contact;
                }
            }
        }

        return array_values($uniqueContacts);
    }

    private function logLine(string $message): string
    {
        $timestamp = (new \DateTimeImmutable())->format('H:i:s');

        return sprintf('[%s] %s', $timestamp, $message)."\n";
    }
}
