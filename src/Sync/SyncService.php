<?php

namespace App\Sync;

use App\Client\Provider\ProviderRegistry;
use App\Client\ReadableListClientInterface;
use App\Client\WriteableListClientInterface;
use App\Contact\Contact;
use App\Contact\ContactListAnalyzer;
use App\Entity\SyncList;
use App\Entity\SyncRun;
use App\Entity\SyncRunContact;
use App\Entity\User;
use App\Event\SyncCompletedEvent;
use App\Repository\ManualContactRepository;
use App\Repository\SyncRunContactRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

class SyncService
{
    public function __construct(
        private readonly ProviderRegistry $providerRegistry,
        private readonly ManualContactRepository $manualContactRepository,
        private readonly SyncRunContactRepository $syncRunContactRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly EventDispatcherInterface $eventDispatcher,
        #[Autowire(service: 'monolog.logger.sync'),]
        private readonly LoggerInterface $logger,
    ) {
    }

    public function executeSync(
        SyncList $syncList,
        bool $dryRun = false,
        ?User $triggeredBy = null,
        string $trigger = 'manual',
        ?SyncRun $existingSyncRun = null,
    ): SyncResult {
        $listName = $syncList->getName();
        $log = '';

        $this->logger->info('Starting sync for list "{list}".', [
            'list' => $listName,
            'sync_list_id' => (string) $syncList->getId(),
            'trigger' => $trigger,
            'dry_run' => $dryRun,
            'triggered_by' => $triggeredBy?->getEmail(),
        ]);

        // Use an existing SyncRun (e.g. pre-created as 'pending' by the controller)
        // or create a new one
        if ($existingSyncRun !== null) {
            $syncRun = $existingSyncRun;
        } else {
            $syncRun = new SyncRun();
            $syncRun->setSyncList($syncList);
            $syncRun->setTriggeredBy($trigger);
            $syncRun->setTriggeredByUser($triggeredBy);
            $this->entityManager->persist($syncRun);
        }

        $syncRun->setStatus('running');
        $syncRun->setStartedAt(new \DateTimeImmutable());
        $this->entityManager->flush();

        try {
            $log .= $this->logLine(sprintf('Processing list: %s', $listName));

            // Build source and destination clients from provider credentials
            $sourceCredential = $syncList->getSourceCredential();
            $destinationCredential = $syncList->getDestinationCredential();

            if ($sourceCredential === null || $destinationCredential === null) {
                throw new \RuntimeException('Sync list is missing source or destination credential configuration.');
            }

            $sourceProvider = $this->providerRegistry->get($sourceCredential->getProviderName());
            $destProvider = $this->providerRegistry->get($destinationCredential->getProviderName());

            $sourceClient = $sourceProvider->createClient($sourceCredential);
            $destClient = $destProvider->createClient($destinationCredential);

            if (!$sourceClient instanceof ReadableListClientInterface) {
                throw new \RuntimeException(sprintf('Source provider "%s" does not support reading contacts.', $sourceCredential->getProviderName()));
            }

            if (!$destClient instanceof WriteableListClientInterface) {
                throw new \RuntimeException(sprintf('Destination provider "%s" does not support writing contacts.', $destinationCredential->getProviderName()));
            }

            // Persist any token refreshes that happened during client creation
            $this->entityManager->flush();

            $sourceListId = $syncList->getSourceListIdentifier() ?? $listName;
            $destListId = $syncList->getDestinationListIdentifier() ?? $listName;

            // Fetch source contacts
            $log .= $this->logLine(
                sprintf('Fetching source contacts from %s...', $sourceProvider->getDisplayName()),
            );
            $sourceContacts = $sourceClient->getContacts($sourceListId);
            $log .= $this->logLine(
                sprintf('  Found %d source contacts', count($sourceContacts)),
            );
            $log .= $this->formatContactList($sourceContacts);

            // Persist source contacts on the sync run
            $this->persistSourceContacts($syncRun, $sourceContacts);

            // Merge with manual contacts
            $manualContacts = $this->getManualContactDtos($syncList);
            $mergedSourceContacts = $this->mergeLists(
                $sourceContacts,
                $manualContacts,
            );

            if (count($manualContacts) > 0) {
                $log .= $this->logLine(
                    sprintf(
                        '  Merged %d manual contacts (total: %d)',
                        count($manualContacts),
                        count($mergedSourceContacts),
                    ),
                );
            }

            // Fetch destination contacts
            $log .= $this->logLine(
                sprintf('Fetching destination contacts from %s...', $destProvider->getDisplayName()),
            );

            if (!$destClient instanceof ReadableListClientInterface) {
                throw new \RuntimeException(sprintf('Destination provider "%s" does not support reading contacts.', $destinationCredential->getProviderName()));
            }

            $destContacts = $destClient->getContacts($destListId);
            $log .= $this->logLine(
                sprintf(
                    '  Found %d destination contacts',
                    count($destContacts),
                ),
            );
            $log .= $this->formatContactList($destContacts);

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
                    $destClient->removeContact($destListId, $contact);
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
                    $destClient->addContact($destListId, $contact);
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

            $this->logger->info(
                'Sync completed for list "{list}": +{added} added, -{removed} removed.',
                [
                    'list' => $listName,
                    'sync_list_id' => (string) $syncList->getId(),
                    'sync_run_id' => (string) $syncRun->getId(),
                    'source_count' => $result->sourceCount,
                    'destination_count' => $result->destinationCount,
                    'added' => $result->addedCount,
                    'removed' => $result->removedCount,
                    'dry_run' => $dryRun,
                ],
            );

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

            $this->logger->error('Sync failed for list "{list}": {error}', [
                'list' => $listName,
                'sync_list_id' => (string) $syncList->getId(),
                'sync_run_id' => (string) $syncRun->getId(),
                'error' => $e->getMessage(),
                'exception' => $e,
            ]);

            $this->eventDispatcher->dispatch(new SyncCompletedEvent($syncRun));

            return $result;
        }
    }

    /**
     * Loads ManualContact entities for a SyncList and converts them to Contact DTOs.
     *
     * @return Contact[]
     */
    private function getManualContactDtos(SyncList $syncList): array
    {
        $entities = $this->manualContactRepository->findBySyncList($syncList);
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

    /**
     * @param Contact[] $contacts
     */
    private function formatContactList(array $contacts): string
    {
        $lines = '';

        foreach ($contacts as $contact) {
            $name = trim(($contact->firstName ?? '').' '.($contact->lastName ?? ''));
            if ($name !== '') {
                $lines .= sprintf("           %s <%s>\n", $name, $contact->email);
            } else {
                $lines .= sprintf("           %s\n", $contact->email);
            }
        }

        return $lines;
    }

    /**
     * Persists source contacts on the SyncRun and cleans up contacts from previous runs.
     *
     * @param Contact[] $sourceContacts
     */
    private function persistSourceContacts(SyncRun $syncRun, array $sourceContacts): void
    {
        // Delete contacts from previous successful runs for this list
        $this->cleanUpOldSourceContacts($syncRun->getSyncList());

        foreach ($sourceContacts as $contact) {
            $name = trim(($contact->firstName ?? '').' '.($contact->lastName ?? ''));

            $syncRunContact = new SyncRunContact();
            $syncRunContact->setName($name !== '' ? $name : ($contact->email ?? ''));
            $syncRunContact->setEmail($contact->email);
            $syncRun->addSyncRunContact($syncRunContact);
        }
    }

    private function cleanUpOldSourceContacts(SyncList $syncList): void
    {
        $oldContacts = $this->syncRunContactRepository->findByLatestSuccessfulRun($syncList);

        foreach ($oldContacts as $contact) {
            $this->entityManager->remove($contact);
        }
    }

    private function logLine(string $message): string
    {
        $timestamp = (new \DateTimeImmutable())->format('H:i:s');

        return sprintf('[%s] %s', $timestamp, $message)."\n";
    }
}
