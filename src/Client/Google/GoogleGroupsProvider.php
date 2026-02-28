<?php

namespace App\Client\Google;

use App\Client\Provider\CredentialFieldDefinition;
use App\Client\Provider\OAuthProviderInterface;
use App\Client\Provider\ProviderCapability;
use App\Client\Provider\ProviderInterface;
use App\Client\WriteableListClientInterface;
use App\Entity\ProviderCredential;
use App\File\FileProvider;
use Google\Client;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('app.provider')]
class GoogleGroupsProvider implements ProviderInterface, OAuthProviderInterface
{
    public function __construct(
        private readonly FileProvider $fileProvider,
        private readonly GoogleServiceFactory $googleServiceFactory,
    ) {
    }

    public function getName(): string
    {
        return 'google_groups';
    }

    public function getDisplayName(): string
    {
        return 'Google Groups';
    }

    public function getCapabilities(): array
    {
        return [ProviderCapability::Destination];
    }

    public function getCredentialFields(): array
    {
        return [
            new CredentialFieldDefinition(
                name: 'oauth_credentials',
                label: 'OAuth Credentials JSON',
                type: 'textarea',
                required: true,
                sensitive: true,
                help: 'Paste the JSON credentials downloaded from Google Cloud Console. Use a "Web application" type credential.',
                placeholder: 'Paste the full OAuth client JSON from Google Cloud Console',
            ),
            new CredentialFieldDefinition(
                name: 'domain',
                label: 'Google Workspace Domain',
                required: true,
                help: 'The Google Workspace domain (e.g. example.com).',
                placeholder: 'e.g. example.com',
            ),
        ];
    }

    public function createClient(ProviderCredential $credential): GoogleClient|WriteableListClientInterface
    {
        $creds = $credential->getCredentialsArray();
        $oauthConfig = json_decode($creds['oauth_credentials'], true, 512, JSON_THROW_ON_ERROR);
        $oauthConfig = $this->normalizeOAuthConfig($oauthConfig, '');
        $domain = $creds['domain'] ?? $credential->getMetadataValue('domain', '');

        $googleClient = new GoogleClient(
            new Client(),
            $this->googleServiceFactory,
            $this->fileProvider,
            $oauthConfig,
            $domain,
            '',
        );

        // Set pre-stored token if available
        $token = $creds['token'] ?? null;
        if ($token !== null) {
            $tokenData = is_string($token) ? json_decode($token, true, 512, JSON_THROW_ON_ERROR) : $token;
            $googleClient->setTokenData($tokenData);
        }

        $googleClient->initialize();

        // Check if token was refreshed and update credential if so
        $currentToken = $googleClient->getTokenData();
        if ($currentToken !== null) {
            $currentTokenJson = json_encode($currentToken, JSON_THROW_ON_ERROR);
            $storedToken = $creds['token'] ?? null;
            if ($currentTokenJson !== $storedToken) {
                $creds['token'] = $currentTokenJson;
                $credential->setCredentials(json_encode($creds, JSON_THROW_ON_ERROR));
            }
        }

        return $googleClient;
    }

    public function getOAuthStartUrl(ProviderCredential $credential, string $callbackUrl): string
    {
        $creds = $credential->getCredentialsArray();
        $oauthConfig = json_decode($creds['oauth_credentials'], true, 512, JSON_THROW_ON_ERROR);
        $domain = $creds['domain'] ?? $credential->getMetadataValue('domain', '');

        // Convert installed credentials to web format and set redirect URI
        $oauthConfig = $this->normalizeOAuthConfig($oauthConfig, $callbackUrl);

        $googleClient = new GoogleClient(
            new Client(),
            $this->googleServiceFactory,
            $this->fileProvider,
            $oauthConfig,
            $domain,
            '',
        );
        $googleClient->configure();

        return $googleClient->createAuthUrl();
    }

    public function handleOAuthCallback(ProviderCredential $credential, string $code, string $callbackUrl): array
    {
        $creds = $credential->getCredentialsArray();
        $oauthConfig = json_decode($creds['oauth_credentials'], true, 512, JSON_THROW_ON_ERROR);
        $domain = $creds['domain'] ?? $credential->getMetadataValue('domain', '');

        $oauthConfig = $this->normalizeOAuthConfig($oauthConfig, $callbackUrl);

        $googleClient = new GoogleClient(
            new Client(),
            $this->googleServiceFactory,
            $this->fileProvider,
            $oauthConfig,
            $domain,
            '',
        );
        $googleClient->configure();

        $googleClient->setAuthCode($code);

        $tokenData = $googleClient->getTokenData();
        $creds['token'] = $tokenData !== null ? json_encode($tokenData, JSON_THROW_ON_ERROR) : null;

        return $creds;
    }

    /**
     * Normalizes OAuth config from "installed" to "web" format and sets the redirect URI.
     *
     * @param array<string, mixed> $config
     *
     * @return array<string, mixed>
     */
    private function normalizeOAuthConfig(array $config, string $callbackUrl): array
    {
        if (isset($config['installed'])) {
            $config['web'] = $config['installed'];
            unset($config['installed']);
        }

        // If credentials were pasted without the "web"/"installed" wrapper,
        // wrap them so Google\Client::setAuthConfig() can find them.
        if (!isset($config['web']) && isset($config['client_id'])) {
            $config = ['web' => $config];
        }

        // Validate that the config contains OAuth client credentials, not a token.
        $inner = $config['web'] ?? $config;
        if (!isset($inner['client_id'])) {
            throw new \InvalidArgumentException('The OAuth credentials JSON is missing "client_id". Edit this credential and paste the OAuth client JSON downloaded from Google Cloud Console (it should contain "client_id" and "client_secret").');
        }

        if (isset($config['web']) && $callbackUrl !== '') {
            $config['web']['redirect_uris'] = [$callbackUrl];
        }

        return $config;
    }
}
