<?php

namespace App\Client\Provider;

use App\Entity\ProviderCredential;

interface ListDiscoverableInterface
{
    /**
     * Returns available lists from the provider for a given credential.
     *
     * @return array<string, string> Map of list identifier => display name
     */
    public function getAvailableLists(ProviderCredential $credential): array;
}
