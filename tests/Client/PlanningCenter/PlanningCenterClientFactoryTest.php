<?php

namespace App\Tests\Client\PlanningCenter;

use App\Client\PlanningCenter\PlanningCenterClient;
use App\Client\PlanningCenter\PlanningCenterClientFactory;
use App\Client\WebClientFactoryInterface;
use App\Entity\Organization;
use GuzzleHttp\ClientInterface;
use Mockery\Adapter\Phpunit\MockeryTestCase;
use Mockery as m;

class PlanningCenterClientFactoryTest extends MockeryTestCase
{
    private const PC_APP_ID = 'test-app-id';
    private const PC_APP_SECRET = 'test-app-secret';

    /** @var WebClientFactoryInterface|m\LegacyMockInterface|m\MockInterface */
    private $webClientFactory;

    private PlanningCenterClientFactory $factory;

    public function setUp(): void
    {
        $this->webClientFactory = m::mock(WebClientFactoryInterface::class);

        $this->webClientFactory
            ->shouldReceive('create')
            ->with(m::type('array'))
            ->andReturn(m::mock(ClientInterface::class));

        $this->factory = new PlanningCenterClientFactory($this->webClientFactory);
    }

    public function testCreateReturnsPlanningCenterClientInstance(): void
    {
        $organization = $this->makeOrganization();

        $result = $this->factory->create($organization);

        self::assertInstanceOf(PlanningCenterClient::class, $result);
    }

    public function testCreatePassesOrganizationCredentialsToWebClientFactory(): void
    {
        $this->webClientFactory = m::mock(WebClientFactoryInterface::class);

        $this->webClientFactory
            ->shouldReceive('create')
            ->once()
            ->with(m::on(function (array $config) {
                return isset($config['auth'])
                    && $config['auth'][0] === self::PC_APP_ID
                    && $config['auth'][1] === self::PC_APP_SECRET;
            }))
            ->andReturn(m::mock(ClientInterface::class));

        $factory = new PlanningCenterClientFactory($this->webClientFactory);
        $organization = $this->makeOrganization();

        $result = $factory->create($organization);

        self::assertInstanceOf(PlanningCenterClient::class, $result);
    }

    public function testCreateUsesCorrectBaseUri(): void
    {
        $this->webClientFactory = m::mock(WebClientFactoryInterface::class);

        $this->webClientFactory
            ->shouldReceive('create')
            ->once()
            ->with(m::on(function (array $config) {
                return isset($config['base_uri'])
                    && $config['base_uri'] === 'https://api.planningcenteronline.com';
            }))
            ->andReturn(m::mock(ClientInterface::class));

        $factory = new PlanningCenterClientFactory($this->webClientFactory);
        $organization = $this->makeOrganization();

        $result = $factory->create($organization);

        self::assertInstanceOf(PlanningCenterClient::class, $result);
    }

    public function testCreateReturnsNewInstanceEachTime(): void
    {
        $organization = $this->makeOrganization();

        $result1 = $this->factory->create($organization);
        $result2 = $this->factory->create($organization);

        self::assertNotSame($result1, $result2);
    }

    public function testCreateWithDifferentOrganizations(): void
    {
        $org1 = $this->makeOrganization('app-id-1', 'secret-1');
        $org2 = $this->makeOrganization('app-id-2', 'secret-2');

        $result1 = $this->factory->create($org1);
        $result2 = $this->factory->create($org2);

        self::assertInstanceOf(PlanningCenterClient::class, $result1);
        self::assertInstanceOf(PlanningCenterClient::class, $result2);
        self::assertNotSame($result1, $result2);
    }

    private function makeOrganization(
        string $appId = self::PC_APP_ID,
        string $appSecret = self::PC_APP_SECRET,
    ): Organization {
        $organization = new Organization();
        $organization->setName('Test Org');
        $organization->setPlanningCenterAppId($appId);
        $organization->setPlanningCenterAppSecret($appSecret);
        $organization->setGoogleOAuthCredentials('{}');
        $organization->setGoogleDomain('example.com');

        return $organization;
    }
}
