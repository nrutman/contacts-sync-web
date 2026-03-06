<?php

namespace App\Tests\Client\Mailchimp;

use App\Client\Mailchimp\MailchimpClient;
use App\Client\Mailchimp\MailchimpProvider;
use App\Client\Provider\ListDiscoverableInterface;
use App\Client\Provider\ProviderCapability;
use App\Client\WebClientFactoryInterface;
use App\Entity\Organization;
use App\Entity\ProviderCredential;
use GuzzleHttp\ClientInterface;
use Mockery\Adapter\Phpunit\MockeryTestCase;
use Mockery as m;

class MailchimpProviderTest extends MockeryTestCase
{
    private WebClientFactoryInterface|m\LegacyMockInterface $webClientFactory;
    private MailchimpProvider $provider;

    protected function setUp(): void
    {
        $this->webClientFactory = m::mock(WebClientFactoryInterface::class);
        $this->webClientFactory
            ->shouldReceive('create')
            ->with(m::type('array'))
            ->andReturn(m::mock(ClientInterface::class));

        $this->provider = new MailchimpProvider($this->webClientFactory);
    }

    public function testGetName(): void
    {
        self::assertEquals('mailchimp', $this->provider->getName());
    }

    public function testGetDisplayName(): void
    {
        self::assertEquals('Mailchimp', $this->provider->getDisplayName());
    }

    public function testGetCapabilities(): void
    {
        $capabilities = $this->provider->getCapabilities();

        self::assertCount(2, $capabilities);
        self::assertContains(ProviderCapability::Source, $capabilities);
        self::assertContains(ProviderCapability::Destination, $capabilities);
    }

    public function testGetCredentialFields(): void
    {
        $fields = $this->provider->getCredentialFields();

        self::assertCount(1, $fields);
        self::assertEquals('api_key', $fields[0]->name);
        self::assertTrue($fields[0]->sensitive);
        self::assertEquals('password', $fields[0]->type);
    }

    public function testCreateClientReturnsMailchimpClient(): void
    {
        $credential = $this->makeCredential(['api_key' => 'abc123-us21']);

        $client = $this->provider->createClient($credential);

        self::assertInstanceOf(MailchimpClient::class, $client);
    }

    public function testImplementsListDiscoverableInterface(): void
    {
        self::assertInstanceOf(ListDiscoverableInterface::class, $this->provider);
    }

    private function makeCredential(array $data): ProviderCredential
    {
        $org = new Organization();
        $org->setName('Test');

        $credential = new ProviderCredential();
        $credential->setOrganization($org);
        $credential->setProviderName('mailchimp');
        $credential->setCredentialsArray($data);

        return $credential;
    }
}
