<?php

namespace App\Client\Mailchimp;

use App\Client\Provider\CredentialFieldDefinition;
use App\Client\Provider\ListDiscoverableInterface;
use App\Client\Provider\ProviderCapability;
use App\Client\Provider\ProviderInterface;
use App\Client\ReadableListClientInterface;
use App\Client\WebClientFactoryInterface;
use App\Client\WriteableListClientInterface;
use App\Entity\ProviderCredential;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('app.provider')]
class MailchimpProvider implements ProviderInterface, ListDiscoverableInterface
{
    public function __construct(
        private readonly WebClientFactoryInterface $webClientFactory,
    ) {
    }

    public function getName(): string
    {
        return 'mailchimp';
    }

    public function getDisplayName(): string
    {
        return 'Mailchimp';
    }

    public function getCapabilities(): array
    {
        return [ProviderCapability::Source, ProviderCapability::Destination];
    }

    public function getCredentialFields(): array
    {
        return [
            new CredentialFieldDefinition(
                name: 'api_key',
                label: 'API Key',
                type: 'password',
                required: true,
                sensitive: true,
                help: 'Your Mailchimp API key. Format: {key}-{dc} (e.g. "abc123def456-us21").',
                placeholder: 'e.g. abc123def456-us21',
            ),
        ];
    }

    public function createClient(ProviderCredential $credential): ReadableListClientInterface|WriteableListClientInterface
    {
        $creds = $credential->getCredentialsArray();

        return new MailchimpClient(
            $creds['api_key'],
            $this->webClientFactory,
        );
    }

    public function getAvailableLists(ProviderCredential $credential): array
    {
        /** @var MailchimpClient $client */
        $client = $this->createClient($credential);

        return $client->getAvailableLists();
    }
}
