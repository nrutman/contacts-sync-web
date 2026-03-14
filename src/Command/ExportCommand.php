<?php

namespace App\Command;

use App\Entity\Organization;
use App\Repository\OrganizationRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:export',
    description: 'Export organization configuration to a JSON file',
)]
class ExportCommand extends Command
{
    public function __construct(
        private readonly OrganizationRepository $orgRepository,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('path', InputArgument::REQUIRED, 'Path to write the export JSON file');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $path = $input->getArgument('path');

        $organization = $this->orgRepository->findOne();

        if ($organization === null) {
            $io->error('No organization found. Nothing to export.');

            return Command::FAILURE;
        }

        $data = [
            'version' => 1,
            'exportedAt' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            'organization' => [
                'id' => (string) $organization->getId(),
                'name' => $organization->getName(),
                'retentionDays' => $organization->getRetentionDays(),
            ],
            'providerCredentials' => $this->exportCredentials($organization),
            'syncLists' => $this->exportSyncLists($organization),
            'manualContacts' => $this->exportManualContacts($organization),
        ];

        $json = json_encode($data, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        if (file_put_contents($path, $json) === false) {
            $io->error(sprintf('Failed to write export file: %s', $path));

            return Command::FAILURE;
        }

        chmod($path, 0600);

        $io->success(sprintf(
            'Exported to %s: %d credential(s), %d list(s), %d manual contact(s)',
            $path,
            count($data['providerCredentials']),
            count($data['syncLists']),
            count($data['manualContacts']),
        ));

        return Command::SUCCESS;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function exportCredentials(Organization $organization): array
    {
        $result = [];

        foreach ($organization->getProviderCredentials() as $credential) {
            $result[] = [
                'id' => (string) $credential->getId(),
                'providerName' => $credential->getProviderName(),
                'label' => $credential->getLabel(),
                'credentials' => $credential->getCredentialsArray(),
                'metadata' => $credential->getMetadata(),
            ];
        }

        return $result;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function exportSyncLists(Organization $organization): array
    {
        $result = [];

        foreach ($organization->getSyncLists() as $syncList) {
            $manualContactIds = [];

            foreach ($syncList->getManualContacts() as $contact) {
                $manualContactIds[] = (string) $contact->getId();
            }

            $result[] = [
                'id' => (string) $syncList->getId(),
                'name' => $syncList->getName(),
                'sourceCredentialId' => $syncList->getSourceCredential() !== null
                    ? (string) $syncList->getSourceCredential()->getId()
                    : null,
                'sourceListIdentifier' => $syncList->getSourceListIdentifier(),
                'destinationCredentialId' => $syncList->getDestinationCredential() !== null
                    ? (string) $syncList->getDestinationCredential()->getId()
                    : null,
                'destinationListIdentifier' => $syncList->getDestinationListIdentifier(),
                'isEnabled' => $syncList->isEnabled(),
                'cronExpression' => $syncList->getCronExpression(),
                'manualContactIds' => $manualContactIds,
            ];
        }

        return $result;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function exportManualContacts(Organization $organization): array
    {
        $result = [];

        foreach ($organization->getManualContacts() as $contact) {
            $result[] = [
                'id' => (string) $contact->getId(),
                'name' => $contact->getName(),
                'email' => $contact->getEmail(),
            ];
        }

        return $result;
    }
}
