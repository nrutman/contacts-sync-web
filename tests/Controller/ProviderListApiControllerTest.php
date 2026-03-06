<?php

namespace App\Tests\Controller;

use App\Client\Provider\ListDiscoverableInterface;
use App\Client\Provider\ProviderInterface;
use App\Client\Provider\ProviderNotFoundException;
use App\Client\Provider\ProviderRegistry;
use App\Controller\ProviderListApiController;
use App\Entity\Organization;
use App\Entity\ProviderCredential;
use Mockery\Adapter\Phpunit\MockeryTestCase;
use Mockery as m;

class ProviderListApiControllerTest extends MockeryTestCase
{
    private ProviderRegistry|m\LegacyMockInterface $providerRegistry;
    private ProviderListApiController $controller;

    protected function setUp(): void
    {
        $this->providerRegistry = m::mock(ProviderRegistry::class);
        $this->controller = new ProviderListApiController($this->providerRegistry);

        $container = new \Symfony\Component\DependencyInjection\Container();
        $this->controller->setContainer($container);
    }

    public function testDiscoverableProviderReturnsLists(): void
    {
        $credential = $this->makeCredential('mailchimp');

        $provider = m::mock(ProviderInterface::class, ListDiscoverableInterface::class);
        $provider->shouldReceive('getAvailableLists')
            ->with($credential)
            ->andReturn(['abc123' => 'Newsletter', 'def456' => 'Members']);

        $this->providerRegistry->shouldReceive('get')
            ->with('mailchimp')
            ->andReturn($provider);

        $response = $this->controller->lists($credential);
        $data = json_decode($response->getContent(), true);

        self::assertSame(200, $response->getStatusCode());
        self::assertTrue($data['discoverable']);
        self::assertEquals(['abc123' => 'Newsletter', 'def456' => 'Members'], $data['lists']);
    }

    public function testNonDiscoverableProviderReturnsFalse(): void
    {
        $credential = $this->makeCredential('some_provider');

        $provider = m::mock(ProviderInterface::class);

        $this->providerRegistry->shouldReceive('get')
            ->with('some_provider')
            ->andReturn($provider);

        $response = $this->controller->lists($credential);
        $data = json_decode($response->getContent(), true);

        self::assertSame(200, $response->getStatusCode());
        self::assertFalse($data['discoverable']);
    }

    public function testProviderExceptionReturnsError(): void
    {
        $credential = $this->makeCredential('mailchimp');

        $provider = m::mock(ProviderInterface::class, ListDiscoverableInterface::class);
        $provider->shouldReceive('getAvailableLists')
            ->with($credential)
            ->andThrow(new \RuntimeException('API connection failed'));

        $this->providerRegistry->shouldReceive('get')
            ->with('mailchimp')
            ->andReturn($provider);

        $response = $this->controller->lists($credential);
        $data = json_decode($response->getContent(), true);

        self::assertSame(500, $response->getStatusCode());
        self::assertTrue($data['discoverable']);
        self::assertEquals('API connection failed', $data['error']);
    }

    public function testUnknownProviderReturnsFalse(): void
    {
        $credential = $this->makeCredential('unknown');

        $this->providerRegistry->shouldReceive('get')
            ->with('unknown')
            ->andThrow(new ProviderNotFoundException('unknown'));

        $response = $this->controller->lists($credential);
        $data = json_decode($response->getContent(), true);

        self::assertSame(200, $response->getStatusCode());
        self::assertFalse($data['discoverable']);
    }

    private function makeCredential(string $providerName): ProviderCredential
    {
        $org = new Organization();
        $org->setName('Test');

        $credential = new ProviderCredential();
        $credential->setOrganization($org);
        $credential->setProviderName($providerName);
        $credential->setCredentialsArray(['key' => 'value']);

        return $credential;
    }
}
