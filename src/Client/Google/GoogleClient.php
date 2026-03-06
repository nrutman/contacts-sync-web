<?php

namespace App\Client\Google;

use App\Client\ReadableListClientInterface;
use App\Client\WriteableListClientInterface;
use App\Contact\Contact;
use App\File\FileProvider;
use Google\Client;
use Google\Exception as GoogleException;
use Google\Service\Directory;
use Google\Service\Directory\Member;
use Symfony\Component\Filesystem\Exception\FileNotFoundException;

class GoogleClient implements
    ReadableListClientInterface,
    WriteableListClientInterface
{
    private const TOKEN_FILENAME = 'google-token.json';

    protected Client $client;
    protected array $configuration;
    protected string $domain;
    protected FileProvider $fileProvider;
    protected Directory $service;
    protected string $varPath;
    private bool $tokenPreSet = false;

    /**
     * @param array<string, mixed> $googleConfiguration
     */
    public function __construct(
        Client $client,
        GoogleServiceFactory $googleServiceFactory,
        FileProvider $fileProvider,
        array $googleConfiguration,
        string $googleDomain,
        string $varPath,
    ) {
        $this->client = $client;
        $this->service = $googleServiceFactory->create($this->client);
        $this->fileProvider = $fileProvider;
        $this->configuration = $googleConfiguration;
        $this->domain = $googleDomain;
        $this->varPath = $varPath;
    }

    /**
     * Configures the underlying Google Client with application settings,
     * scopes, and auth config — without loading or validating tokens.
     */
    public function configure(): self
    {
        $this->client->setApplicationName('Contacts Sync');
        $this->client->setScopes([
            Directory::ADMIN_DIRECTORY_GROUP,
            Directory::ADMIN_DIRECTORY_GROUP_MEMBER,
        ]);
        $this->client->setAuthConfig($this->configuration);
        $this->client->setAccessType('offline');
        $this->client->setPrompt('select_account consent');
        $this->client->setHostedDomain($this->domain);

        return $this;
    }

    /**
     * Initializes a Google Client based on configuration.
     *
     * @see https://developers.google.com/admin-sdk/directory/v1/quickstart/php
     *
     * @throws FileNotFoundException
     * @throws GoogleException
     */
    public function initialize(): self
    {
        $this->configure();

        // If a token was pre-set via setTokenData(), use it directly;
        // otherwise fall back to loading from the saved file.
        if (!$this->tokenPreSet) {
            try {
                $this->client->setAccessToken($this->getToken());
            } catch (\InvalidArgumentException $invalidArgumentException) {
                throw new InvalidGoogleTokenException($invalidArgumentException);
            }
        }

        if ($this->client->isAccessTokenExpired()) {
            if (!$this->client->getRefreshToken()) {
                throw new InvalidGoogleTokenException();
            }
            $this->client->fetchAccessTokenWithRefreshToken(
                $this->client->getRefreshToken(),
            );
            $this->saveToken();
        }

        return $this;
    }

    /**
     * Pre-sets the OAuth token data from an external source (e.g. database).
     * When set, initialize() will skip file-based token loading.
     *
     * @param array<string, mixed> $token
     */
    public function setTokenData(array $token): void
    {
        $this->client->setAccessToken($token);
        $this->tokenPreSet = true;
    }

    /**
     * Returns the current OAuth token data from the underlying Google Client.
     *
     * @return array<string, mixed>|null
     */
    public function getTokenData(): ?array
    {
        return $this->client->getAccessToken() ?: null;
    }

    public function createAuthUrl(): string
    {
        return $this->client->createAuthUrl();
    }

    public function setAuthCode(string $authCode): void
    {
        $accessToken = $this->client->fetchAccessTokenWithAuthCode($authCode);
        $this->client->setAccessToken($accessToken);

        // Check to see if there was an error.
        if (array_key_exists('error', $accessToken)) {
            $exception = new \RuntimeException(implode(', ', $accessToken));
            throw new InvalidGoogleTokenException($exception);
        }

        $this->saveToken();
    }

    /**
     * Returns available groups as an [email => name] map.
     *
     * @return array<string, string>
     */
    public function getAvailableGroups(): array
    {
        $groups = [];
        $pageToken = null;

        do {
            $params = ['domain' => $this->domain];
            if ($pageToken !== null) {
                $params['pageToken'] = $pageToken;
            }

            $result = $this->service->groups->listGroups($params);

            foreach ((array) $result->getGroups() as $group) {
                $groups[$group->getEmail()] = $group->getName();
            }

            $pageToken = $result->getNextPageToken();
        } while ($pageToken !== null);

        return $groups;
    }

    public function getContacts(string $listName): array
    {
        return array_map(
            self::memberToContact(...),
            (array) $this->service->members
                ->listMembers($listName)
                ->getMembers(),
        );
    }

    public function addContact(string $list, Contact $contact): void
    {
        $member = self::contactToMember($contact);
        $this->service->members->insert($list, $member);
    }

    public function removeContact(string $list, Contact $contact): void
    {
        $this->service->members->delete($list, $contact->email);
    }

    private static function contactToMember(Contact $contact): Member
    {
        $member = new Member();
        $member->setEmail($contact->email);

        return $member;
    }

    /**
     * @see getContacts
     */
    private static function memberToContact(Member $member): Contact
    {
        $contact = new Contact();
        $contact->email = $member->getEmail();

        return $contact;
    }

    /**
     * @throws FileNotFoundException
     */
    private function getToken(): array
    {
        return json_decode(
            $this->fileProvider->getContents($this->getTokenPath()),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
    }

    private function getTokenPath(): string
    {
        return sprintf('%s/%s', $this->varPath, self::TOKEN_FILENAME);
    }

    private function saveToken(): void
    {
        // Skip file-based token storage when no varPath is configured;
        // the web flow persists tokens to the database instead.
        if ($this->varPath === '') {
            return;
        }

        $this->fileProvider->saveContents(
            $this->getTokenPath(),
            json_encode($this->client->getAccessToken(), JSON_THROW_ON_ERROR),
        );
    }
}
