<?php

namespace App\Client\Provider;

use App\Entity\ProviderCredential;

interface RefreshableProviderInterface
{
    /**
     * Refreshes a source list so it contains the most up-to-date contacts.
     */
    public function refreshList(ProviderCredential $credential, string $listName): void;
}
