<?php

namespace App\Tests\Client\PlanningCenter;

use App\Client\PlanningCenter\PlanningCenterClient;
use App\Client\PlanningCenter\PlanningCenterProvider;
use App\Client\Provider\ProviderCapability;
use App\Client\WebClientFactoryInterface;
use App\Entity\Organization;
use App\Entity\ProviderCredential;
use GuzzleHttp\ClientInterface;
use Mockery\Adapter\Phpunit\MockeryTestCase;
use Mockery as m;

class PlanningCenterProviderTest extends MockeryTestCase
{
    private WebClientFactoryInterface|m\LegacyMockInterface $webClientFactory;
    private PlanningCenterProvider $provider;

    protected function setUp(): void
    {
        $this->webClientFactory = m::mock(WebClientFactoryInterface::class);
        $this->webClientFactory
            ->shouldReceive('create')
            ->with(m::type('array'))
            ->andReturn(m::mock(ClientInterface::class));

        $this->provider = new PlanningCenterProvider($this->webClientFactory);
    }

    public function testGetName(): void
    {
        self::assertEquals('planning_center', $this->provider->getName());
    }

    public function testGetDisplayName(): void
    {
        self::assertEquals('Planning Center', $this->provider->getDisplayName());
    }

    public function testGetCapabilities(): void
    {
        $capabilities = $this->provider->getCapabilities();

        self::assertCount(1, $capabilities);
        self::assertContains(ProviderCapability::Source, $capabilities);
        self::assertNotContains(ProviderCapability::Destination, $capabilities);
    }

    public function testGetCredentialFields(): void
    {
        $fields = $this->provider->getCredentialFields();

        self::assertCount(2, $fields);
        self::assertEquals('app_id', $fields[0]->name);
        self::assertEquals('app_secret', $fields[1]->name);
        self::assertTrue($fields[1]->sensitive);
    }

    public function testCreateClientReturnsPlanningCenterClient(): void
    {
        $credential = $this->makeCredential([
            'app_id' => 'test-id',
            'app_secret' => 'test-secret',
        ]);

        $client = $this->provider->createClient($credential);

        self::assertInstanceOf(PlanningCenterClient::class, $client);
    }

    private function makeCredential(array $data): ProviderCredential
    {
        $org = new Organization();
        $org->setName('Test');

        $credential = new ProviderCredential();
        $credential->setOrganization($org);
        $credential->setProviderName('planning_center');
        $credential->setCredentialsArray($data);

        return $credential;
    }
}
