<?php

namespace App\Tests\Client\Google;

use App\Client\Google\GoogleGroupsProvider;
use App\Client\Google\GoogleServiceFactory;
use App\Client\Provider\ProviderCapability;
use App\Client\WebClientFactoryInterface;
use App\Entity\Organization;
use App\Entity\ProviderCredential;
use App\File\FileProvider;
use Google\Client;
use Google\Service\Directory;
use GuzzleHttp\Client as GuzzleClient;
use Mockery\Adapter\Phpunit\MockeryTestCase;
use Mockery as m;

class GoogleGroupsProviderTest extends MockeryTestCase
{
    private FileProvider|m\LegacyMockInterface $fileProvider;
    private GoogleServiceFactory|m\LegacyMockInterface $googleServiceFactory;
    private WebClientFactoryInterface|m\LegacyMockInterface $webClientFactory;
    private GoogleGroupsProvider $provider;

    protected function setUp(): void
    {
        $this->fileProvider = m::mock(FileProvider::class);
        $this->googleServiceFactory = m::mock(GoogleServiceFactory::class);
        $this->webClientFactory = m::mock(WebClientFactoryInterface::class);

        $this->googleServiceFactory
            ->shouldReceive('create')
            ->with(m::type(Client::class))
            ->andReturn(m::mock(Directory::class));

        $this->webClientFactory
            ->shouldReceive('create')
            ->andReturn(new GuzzleClient());

        $this->provider = new GoogleGroupsProvider(
            $this->fileProvider,
            $this->googleServiceFactory,
            $this->webClientFactory,
        );
    }

    public function testGetName(): void
    {
        self::assertEquals('google_groups', $this->provider->getName());
    }

    public function testGetDisplayName(): void
    {
        self::assertEquals('Google Groups', $this->provider->getDisplayName());
    }

    public function testGetCapabilities(): void
    {
        $capabilities = $this->provider->getCapabilities();

        self::assertCount(1, $capabilities);
        self::assertContains(ProviderCapability::Destination, $capabilities);
        self::assertNotContains(ProviderCapability::Source, $capabilities);
    }

    public function testGetCredentialFields(): void
    {
        $fields = $this->provider->getCredentialFields();

        self::assertCount(2, $fields);
        self::assertEquals('oauth_credentials', $fields[0]->name);
        self::assertTrue($fields[0]->sensitive);
        self::assertEquals('domain', $fields[1]->name);
    }

    public function testImplementsListDiscoverableInterface(): void
    {
        self::assertInstanceOf(
            \App\Client\Provider\ListDiscoverableInterface::class,
            $this->provider,
        );
    }

    public function testImplementsOAuthProviderInterface(): void
    {
        self::assertInstanceOf(
            \App\Client\Provider\OAuthProviderInterface::class,
            $this->provider,
        );
    }

    public function testNormalizeOAuthConfigSetsRedirectUriWhenCallbackProvided(): void
    {
        $method = new \ReflectionMethod($this->provider, 'normalizeOAuthConfig');
        $config = ['web' => ['client_id' => 'test', 'client_secret' => 'secret']];

        $result = $method->invoke($this->provider, $config, 'https://example.com/callback');

        self::assertEquals(['https://example.com/callback'], $result['web']['redirect_uris']);
    }

    public function testNormalizeOAuthConfigDoesNotSetEmptyRedirectUri(): void
    {
        $method = new \ReflectionMethod($this->provider, 'normalizeOAuthConfig');
        $config = ['web' => ['client_id' => 'test', 'client_secret' => 'secret']];

        $result = $method->invoke($this->provider, $config, '');

        self::assertArrayNotHasKey('redirect_uris', $result['web']);
    }

    private function makeCredential(array $data): ProviderCredential
    {
        $org = new Organization();
        $org->setName('Test');

        $credential = new ProviderCredential();
        $credential->setOrganization($org);
        $credential->setProviderName('google_groups');
        $credential->setCredentialsArray($data);

        return $credential;
    }
}
