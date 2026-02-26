<?php

namespace App\Client\Provider;

use App\Client\ReadableListClientInterface;
use App\Client\WriteableListClientInterface;
use App\Entity\ProviderCredential;

interface ProviderInterface
{
    /**
     * Unique machine name for this provider (e.g. 'planning_center', 'google_groups').
     */
    public function getName(): string;

    /**
     * Human-readable display name (e.g. 'Planning Center', 'Google Groups').
     */
    public function getDisplayName(): string;

    /**
     * Returns the capabilities this provider supports (source, destination, or both).
     *
     * @return ProviderCapability[]
     */
    public function getCapabilities(): array;

    /**
     * Describes the credential fields needed for configuration.
     *
     * @return CredentialFieldDefinition[]
     */
    public function getCredentialFields(): array;

    /**
     * Creates a client instance from stored credentials.
     */
    public function createClient(ProviderCredential $credential): ReadableListClientInterface|WriteableListClientInterface;
}
