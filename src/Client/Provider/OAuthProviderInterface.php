<?php

namespace App\Client\Provider;

use App\Entity\ProviderCredential;

interface OAuthProviderInterface
{
    /**
     * Generates the URL to start the OAuth flow.
     */
    public function getOAuthStartUrl(ProviderCredential $credential, string $callbackUrl): string;

    /**
     * Handles the OAuth callback and updates the credential with the token.
     * Returns the updated credentials blob as an array.
     *
     * @return array<string, mixed> The updated credentials to persist
     */
    public function handleOAuthCallback(ProviderCredential $credential, string $code, string $callbackUrl): array;
}
