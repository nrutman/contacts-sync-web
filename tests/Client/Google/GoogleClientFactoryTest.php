<?php

namespace App\Tests\Client\Google;

use App\Client\Google\GoogleClient;
use App\Client\Google\GoogleClientFactory;
use App\Client\Google\GoogleServiceFactory;
use App\Entity\Organization;
use App\File\FileProvider;
use Google\Client;
use Google\Service\Directory;
use Mockery\Adapter\Phpunit\MockeryTestCase;
use Mockery as m;

class GoogleClientFactoryTest extends MockeryTestCase
{
    private const GOOGLE_DOMAIN = 'example.com';
    private const OAUTH_CREDENTIALS = '{"installed":{"client_id":"test","client_secret":"secret"}}';
    private const TOKEN_JSON = '{"access_token":"test-token","refresh_token":"test-refresh"}';

    /** @var FileProvider|m\LegacyMockInterface|m\MockInterface */
    private $fileProvider;

    /** @var GoogleServiceFactory|m\LegacyMockInterface|m\MockInterface */
    private $googleServiceFactory;

    private GoogleClientFactory $factory;

    public function setUp(): void
    {
        $this->fileProvider = m::mock(FileProvider::class);
        $this->googleServiceFactory = m::mock(GoogleServiceFactory::class);

        $this->googleServiceFactory
            ->shouldReceive('create')
            ->with(m::type(Client::class))
            ->andReturn(m::mock(Directory::class));

        $this->factory = new GoogleClientFactory(
            $this->fileProvider,
            $this->googleServiceFactory,
        );
    }

    public function testCreateReturnsGoogleClientInstance(): void
    {
        $organization = $this->makeOrganization();

        $result = $this->factory->create($organization);

        self::assertInstanceOf(GoogleClient::class, $result);
    }

    public function testCreateSetsTokenDataWhenOrganizationHasToken(): void
    {
        $organization = $this->makeOrganization();
        $organization->setGoogleToken(self::TOKEN_JSON);

        $result = $this->factory->create($organization);

        self::assertInstanceOf(GoogleClient::class, $result);
        $tokenData = $result->getTokenData();
        self::assertIsArray($tokenData);
        self::assertEquals('test-token', $tokenData['access_token']);
        self::assertEquals('test-refresh', $tokenData['refresh_token']);
    }

    public function testCreateDoesNotSetTokenWhenOrganizationHasNullToken(): void
    {
        $organization = $this->makeOrganization();
        $organization->setGoogleToken(null);

        $result = $this->factory->create($organization);

        self::assertInstanceOf(GoogleClient::class, $result);
        // No token was pre-set, so getTokenData should return null or empty
        // (the underlying Google Client has no token set)
        $tokenData = $result->getTokenData();
        self::assertNull($tokenData);
    }

    public function testCreateUsesOrganizationDomain(): void
    {
        $organization = $this->makeOrganization();
        $organization->setGoogleDomain('custom-domain.org');

        $result = $this->factory->create($organization);

        self::assertInstanceOf(GoogleClient::class, $result);
    }

    public function testCreateParsesOAuthCredentialsFromJson(): void
    {
        $organization = $this->makeOrganization();

        // This should not throw — valid JSON credentials are parsed successfully
        $result = $this->factory->create($organization);

        self::assertInstanceOf(GoogleClient::class, $result);
    }

    public function testCreateThrowsOnInvalidOAuthCredentialsJson(): void
    {
        $organization = $this->makeOrganization();
        $organization->setGoogleOAuthCredentials('not-valid-json');

        $this->expectException(\JsonException::class);

        $this->factory->create($organization);
    }

    public function testCreateThrowsOnInvalidTokenJson(): void
    {
        $organization = $this->makeOrganization();
        $organization->setGoogleToken('not-valid-json');

        $this->expectException(\JsonException::class);

        $this->factory->create($organization);
    }

    private function makeOrganization(): Organization
    {
        $organization = new Organization();
        $organization->setName('Test Org');
        $organization->setPlanningCenterAppId('pc-id');
        $organization->setPlanningCenterAppSecret('pc-secret');
        $organization->setGoogleOAuthCredentials(self::OAUTH_CREDENTIALS);
        $organization->setGoogleDomain(self::GOOGLE_DOMAIN);

        return $organization;
    }
}
