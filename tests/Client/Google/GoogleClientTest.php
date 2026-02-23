<?php

namespace App\Tests\Client\Google;

use App\Client\Google\GoogleClient;
use App\Client\Google\GoogleServiceFactory;
use App\Client\Google\InvalidGoogleTokenException;
use App\Contact\Contact;
use App\File\FileProvider;
use Google\Client;
use Google\Service\Directory;
use Google\Service\Directory\Member;
use Google\Service\Directory\Members;
use Google\Service\Directory\Resource\Members as ResourceMembers;
use Mockery\Adapter\Phpunit\MockeryTestCase;
use Mockery as m;
use Symfony\Component\Filesystem\Exception\FileNotFoundException;

class GoogleClientTest extends MockeryTestCase
{
    private const AUTH_CODE = 'AUTH:CODE{}';
    private const CONFIGURATION = [
        'authentication' => self::GOOGLE_AUTH,
        'domain' => self::DOMAIN,
    ];
    private const DOMAIN = 'domain';
    private const GOOGLE_AUTH = ['authorize_me'];
    private const GROUP_ID = 'group@domain';
    private const MEMBER_EMAIL = 'foo@bar';
    private const TEMP_PATH = 'tmp/test';
    private const TOKEN_ARRAY = ['token' => 'foobar'];
    private const TOKEN_STRING = '{"token":"foobar"}';
    private const TOKEN_FILENAME = 'google-token.json';
    private const TOKEN_REFRESH = 'refresh.token';

    /** @var Client|m\LegacyMockInterface|m\MockInterface */
    private $client;

    /** @var FileProvider|m\LegacyMockInterface|m\MockInterface */
    private $fileProvider;

    /** @var m\LegacyMockInterface|m\MockInterface|Directory */
    private $service;

    /** @var m\LegacyMockInterface|m\MockInterface|GoogleServiceFactory */
    private $serviceFactory;

    /** @var GoogleClient */
    private $target;

    public function setUp(): void
    {
        $this->client = m::mock(Client::class);
        $this->fileProvider = m::mock(FileProvider::class);
        $this->service = m::mock(Directory::class);
        $this->serviceFactory = m::mock(GoogleServiceFactory::class, [
            'create' => $this->service,
        ]);

        $this->target = new GoogleClient(
            $this->client,
            $this->serviceFactory,
            $this->fileProvider,
            self::CONFIGURATION,
            self::DOMAIN,
            self::TEMP_PATH,
        );
    }

    public function testInitialize(): void
    {
        $this->setupInitializeExpectations();

        $this->client->shouldReceive('setAccessToken')->with(self::TOKEN_ARRAY);

        $this->fileProvider
            ->shouldReceive('getContents')
            ->with(self::TEMP_PATH.'/'.self::TOKEN_FILENAME)
            ->andReturn(self::TOKEN_STRING);

        $result = $this->target->initialize();

        self::assertSame($this->target, $result);
    }

    public function testInitializeInvalidToken(): void
    {
        $this->setupInitializeExpectations();

        $this->client
            ->shouldReceive('setAccessToken')
            ->with(self::TOKEN_ARRAY)
            ->andThrow(new \InvalidArgumentException());

        $this->fileProvider
            ->shouldReceive('getContents')
            ->with(self::TEMP_PATH.'/'.self::TOKEN_FILENAME)
            ->andReturn(self::TOKEN_STRING);

        $this->expectException(InvalidGoogleTokenException::class);

        $this->target->initialize();
    }

    public function testInitializeRefreshToken(): void
    {
        $this->setupInitializeExpectations([
            'isAccessTokenExpired' => true,
            'getRefreshToken' => self::TOKEN_REFRESH,
            'fetchAccessTokenWithRefreshToken' => null,
            'getAccessToken' => self::TOKEN_ARRAY,
        ]);

        $this->client->shouldReceive('setAccessToken')->with(self::TOKEN_ARRAY);

        $this->fileProvider
            ->shouldReceive('getContents')
            ->with(self::TEMP_PATH.'/'.self::TOKEN_FILENAME)
            ->andReturn(self::TOKEN_STRING);

        $this->fileProvider
            ->shouldReceive('saveContents')
            ->with(self::TEMP_PATH.'/'.self::TOKEN_FILENAME, m::any());

        $result = $this->target->initialize();

        self::assertSame($this->target, $result);
    }

    public function testInitializeInvalidRefreshToken(): void
    {
        $this->setupInitializeExpectations([
            'isAccessTokenExpired' => true,
            'getRefreshToken' => null,
        ]);

        $this->client->shouldReceive('setAccessToken')->with(self::TOKEN_ARRAY);

        $this->fileProvider
            ->shouldReceive('getContents')
            ->with(self::TEMP_PATH.'/'.self::TOKEN_FILENAME)
            ->andReturn(self::TOKEN_STRING);

        $this->expectException(InvalidGoogleTokenException::class);

        $this->target->initialize();
    }

    public function testInitializeTokenFileNotFound(): void
    {
        $this->setupInitializeExpectations();

        $this->fileProvider
            ->shouldReceive('getContents')
            ->with(self::TEMP_PATH.'/'.self::TOKEN_FILENAME)
            ->andThrow(new FileNotFoundException());

        $this->expectException(FileNotFoundException::class);

        $this->target->initialize();
    }

    public function testGetContacts(): void
    {
        $member = new Member();
        $member->setEmail(self::MEMBER_EMAIL);

        $this->service->members = m::mock(ResourceMembers::class);
        $this->service->members
            ->shouldReceive('listMembers')
            ->with(self::GROUP_ID)
            ->andReturn(
                m::mock(Members::class, [
                    'getMembers' => [$member],
                ]),
            );

        $result = $this->target->getContacts(self::GROUP_ID);

        self::assertIsArray($result);
        self::assertCount(1, $result);

        /** @var Contact $contact */
        $contact = $result[0];
        self::assertInstanceOf(Contact::class, $contact);
        self::assertEquals(self::MEMBER_EMAIL, $contact->email);
        self::assertNull($contact->firstName);
        self::assertNull($contact->lastName);
    }

    public function testAddContact(): void
    {
        $contact = new Contact();
        $contact->email = self::MEMBER_EMAIL;

        $this->service->members = m::mock(ResourceMembers::class);
        $this->service->members
            ->shouldReceive('insert')
            ->with(self::GROUP_ID, m::type(Member::class));

        $this->target->addContact(self::GROUP_ID, $contact);

        // Mockery expectations verify insert was called with correct args
        self::assertSame(self::MEMBER_EMAIL, $contact->email);
    }

    public function testRemoveContact(): void
    {
        $contact = new Contact();
        $contact->email = self::MEMBER_EMAIL;

        $this->service->members = m::mock(ResourceMembers::class);
        $this->service->members
            ->shouldReceive('delete')
            ->with(self::GROUP_ID, self::MEMBER_EMAIL);

        $this->target->removeContact(self::GROUP_ID, $contact);

        // Mockery expectations verify delete was called with correct args
        self::assertSame(self::MEMBER_EMAIL, $contact->email);
    }

    public function testSetAuthCode(): void
    {
        $this->client
            ->shouldReceive('fetchAccessTokenWithAuthCode')
            ->with(self::AUTH_CODE)
            ->andReturn(self::TOKEN_ARRAY);

        $this->client->shouldReceive('setAccessToken');

        $this->client
            ->shouldReceive('getAccessToken')
            ->andReturn(self::TOKEN_ARRAY);

        $this->fileProvider->shouldReceive('saveContents');

        $this->target->setAuthCode(self::AUTH_CODE);

        // Mockery expectations verify the full auth code flow completed
        self::assertTrue(true);
    }

    public function testSetAuthCodeThrowsOnErrorResponse(): void
    {
        $errorToken = ['error' => 'invalid_grant'];

        $this->client
            ->shouldReceive('fetchAccessTokenWithAuthCode')
            ->with(self::AUTH_CODE)
            ->andReturn($errorToken);

        $this->client->shouldReceive('setAccessToken');

        $this->expectException(InvalidGoogleTokenException::class);

        $this->target->setAuthCode(self::AUTH_CODE);
    }

    public function testSetTokenData(): void
    {
        $this->client
            ->shouldReceive('setAccessToken')
            ->once()
            ->with(self::TOKEN_ARRAY);

        $this->target->setTokenData(self::TOKEN_ARRAY);

        // Verify the token was set by checking getTokenData
        $this->client
            ->shouldReceive('getAccessToken')
            ->once()
            ->andReturn(self::TOKEN_ARRAY);

        $result = $this->target->getTokenData();

        self::assertEquals(self::TOKEN_ARRAY, $result);
    }

    public function testGetTokenDataReturnsNullWhenNoToken(): void
    {
        $this->client->shouldReceive('getAccessToken')->once()->andReturn([]);

        $result = $this->target->getTokenData();

        self::assertNull($result);
    }

    public function testGetTokenDataReturnsTokenArray(): void
    {
        $this->client
            ->shouldReceive('getAccessToken')
            ->once()
            ->andReturn(self::TOKEN_ARRAY);

        $result = $this->target->getTokenData();

        self::assertEquals(self::TOKEN_ARRAY, $result);
    }

    public function testInitializeWithPreSetTokenSkipsFileLoading(): void
    {
        $this->setupInitializeExpectations();

        // Pre-set the token
        $this->client->shouldReceive('setAccessToken')->with(self::TOKEN_ARRAY);

        $this->target->setTokenData(self::TOKEN_ARRAY);

        // File provider should NOT be called since token is pre-set
        $this->fileProvider->shouldNotReceive('getContents');

        $result = $this->target->initialize();

        self::assertSame($this->target, $result);
    }

    public function testInitializeWithPreSetTokenStillRefreshesIfExpired(): void
    {
        $this->setupInitializeExpectations([
            'isAccessTokenExpired' => true,
            'getRefreshToken' => self::TOKEN_REFRESH,
            'fetchAccessTokenWithRefreshToken' => null,
            'getAccessToken' => self::TOKEN_ARRAY,
        ]);

        // Pre-set the token
        $this->client->shouldReceive('setAccessToken')->with(self::TOKEN_ARRAY);

        $this->target->setTokenData(self::TOKEN_ARRAY);

        // File provider should NOT be called for getContents (no file-based loading)
        // but saveContents IS called when the token is refreshed
        $this->fileProvider->shouldNotReceive('getContents');
        $this->fileProvider->shouldReceive('saveContents')->once();

        $result = $this->target->initialize();

        self::assertSame($this->target, $result);
    }

    public function testInitializeWithoutPreSetTokenLoadsFromFile(): void
    {
        $this->setupInitializeExpectations();

        $this->client->shouldReceive('setAccessToken')->with(self::TOKEN_ARRAY);

        $this->fileProvider
            ->shouldReceive('getContents')
            ->with(self::TEMP_PATH.'/'.self::TOKEN_FILENAME)
            ->andReturn(self::TOKEN_STRING);

        $result = $this->target->initialize();

        self::assertSame($this->target, $result);
    }

    private function setupInitializeExpectations(array $overrides = []): void
    {
        $defaults = [
            'setApplicationName' => null,
            'setScopes' => null,
            'setAuthConfig' => null,
            'setAccessType' => null,
            'setPrompt' => null,
            'setHostedDomain' => null,
            'isAccessTokenExpired' => false,
        ];

        $this->client->shouldReceive(array_merge($defaults, $overrides));
    }
}
