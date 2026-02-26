<?php

namespace App\Tests\Client\Google;

use App\Client\Google\GoogleGroupsProvider;
use App\Client\Google\GoogleServiceFactory;
use App\Client\Provider\ProviderCapability;
use App\Entity\Organization;
use App\Entity\ProviderCredential;
use App\File\FileProvider;
use Google\Client;
use Google\Service\Directory;
use Mockery\Adapter\Phpunit\MockeryTestCase;
use Mockery as m;

class GoogleGroupsProviderTest extends MockeryTestCase
{
    private FileProvider|m\LegacyMockInterface $fileProvider;
    private GoogleServiceFactory|m\LegacyMockInterface $googleServiceFactory;
    private GoogleGroupsProvider $provider;

    protected function setUp(): void
    {
        $this->fileProvider = m::mock(FileProvider::class);
        $this->googleServiceFactory = m::mock(GoogleServiceFactory::class);

        $this->googleServiceFactory
            ->shouldReceive('create')
            ->with(m::type(Client::class))
            ->andReturn(m::mock(Directory::class));

        $this->provider = new GoogleGroupsProvider(
            $this->fileProvider,
            $this->googleServiceFactory,
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

    public function testImplementsOAuthProviderInterface(): void
    {
        self::assertInstanceOf(
            \App\Client\Provider\OAuthProviderInterface::class,
            $this->provider,
        );
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
