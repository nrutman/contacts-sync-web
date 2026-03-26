<?php

namespace App\Client\PlanningCenter;

use App\Client\Provider\CredentialFieldDefinition;
use App\Client\Provider\ListDiscoverableInterface;
use App\Client\Provider\ProviderCapability;
use App\Client\Provider\ProviderInterface;
use App\Client\Provider\RefreshableProviderInterface;
use App\Client\ReadableListClientInterface;
use App\Client\WebClientFactoryInterface;
use App\Entity\ProviderCredential;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('app.provider')]
class PlanningCenterProvider implements ProviderInterface, ListDiscoverableInterface, RefreshableProviderInterface
{
    public function __construct(
        private readonly WebClientFactoryInterface $webClientFactory,
    ) {
    }

    public function getName(): string
    {
        return 'planning_center';
    }

    public function getDisplayName(): string
    {
        return 'Planning Center';
    }

    public function getCapabilities(): array
    {
        return [ProviderCapability::Source];
    }

    public function getCredentialFields(): array
    {
        return [
            new CredentialFieldDefinition(
                name: 'app_id',
                label: 'Application ID',
                required: true,
                help: 'Your Planning Center API application ID.',
                placeholder: 'Your Planning Center application ID',
            ),
            new CredentialFieldDefinition(
                name: 'app_secret',
                label: 'Application Secret',
                type: 'password',
                required: true,
                sensitive: true,
                help: 'Your Planning Center API application secret.',
                placeholder: 'Your Planning Center application secret',
            ),
        ];
    }

    public function createClient(ProviderCredential $credential): ReadableListClientInterface
    {
        $creds = $credential->getCredentialsArray();

        return new PlanningCenterClient(
            $creds['app_id'],
            $creds['app_secret'],
            $this->webClientFactory,
        );
    }

    public function getAvailableLists(ProviderCredential $credential): array
    {
        /** @var PlanningCenterClient $client */
        $client = $this->createClient($credential);

        return $client->getAvailableLists();
    }

    /**
     * Refreshes a source list so it contains the most up-to-date contacts.
     */
    public function refreshList(ProviderCredential $credential, string $listName): void
    {
        $client = $this->createClient($credential);
        $client->refreshList($listName);
    }
}
