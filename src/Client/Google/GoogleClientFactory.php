<?php

namespace App\Client\Google;

use App\Entity\Organization;
use App\File\FileProvider;
use Google\Client;

class GoogleClientFactory
{
    public function __construct(
        private readonly FileProvider $fileProvider,
        private readonly GoogleServiceFactory $googleServiceFactory,
    ) {
    }

    /**
     * Creates a GoogleClient configured for a specific organization.
     * Credentials are already decrypted by the Doctrine listener.
     */
    public function create(Organization $organization): GoogleClient
    {
        $googleClient = new GoogleClient(
            new Client(),
            $this->googleServiceFactory,
            $this->fileProvider,
            json_decode($organization->getGoogleOAuthCredentials(), true, 512, JSON_THROW_ON_ERROR),
            $organization->getGoogleDomain(),
            '',
        );

        $token = $organization->getGoogleToken();

        if ($token !== null) {
            $googleClient->setTokenData(
                json_decode($token, true, 512, JSON_THROW_ON_ERROR),
            );
        }

        return $googleClient;
    }
}
