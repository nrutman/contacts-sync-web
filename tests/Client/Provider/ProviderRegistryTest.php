<?php

namespace App\Tests\Client\Provider;

use App\Client\Provider\ProviderCapability;
use App\Client\Provider\ProviderInterface;
use App\Client\Provider\ProviderNotFoundException;
use App\Client\Provider\ProviderRegistry;
use Mockery\Adapter\Phpunit\MockeryTestCase;
use Mockery as m;

class ProviderRegistryTest extends MockeryTestCase
{
    public function testGetReturnsProviderByName(): void
    {
        $provider = $this->makeProvider('test_provider', [ProviderCapability::Source]);
        $registry = new ProviderRegistry([$provider]);

        self::assertSame($provider, $registry->get('test_provider'));
    }

    public function testGetThrowsForUnknownProvider(): void
    {
        $registry = new ProviderRegistry([]);

        $this->expectException(ProviderNotFoundException::class);
        $this->expectExceptionMessage('Provider "unknown" not found.');

        $registry->get('unknown');
    }

    public function testAllReturnsAllProviders(): void
    {
        $source = $this->makeProvider('source', [ProviderCapability::Source]);
        $dest = $this->makeProvider('dest', [ProviderCapability::Destination]);

        $registry = new ProviderRegistry([$source, $dest]);

        self::assertCount(2, $registry->all());
        self::assertSame($source, $registry->all()['source']);
        self::assertSame($dest, $registry->all()['dest']);
    }

    public function testGetSourcesFiltersToSourceCapable(): void
    {
        $source = $this->makeProvider('source', [ProviderCapability::Source]);
        $dest = $this->makeProvider('dest', [ProviderCapability::Destination]);
        $both = $this->makeProvider('both', [ProviderCapability::Source, ProviderCapability::Destination]);

        $registry = new ProviderRegistry([$source, $dest, $both]);
        $sources = $registry->getSources();

        self::assertCount(2, $sources);
        self::assertArrayHasKey('source', $sources);
        self::assertArrayHasKey('both', $sources);
        self::assertArrayNotHasKey('dest', $sources);
    }

    public function testGetDestinationsFiltersToDestinationCapable(): void
    {
        $source = $this->makeProvider('source', [ProviderCapability::Source]);
        $dest = $this->makeProvider('dest', [ProviderCapability::Destination]);
        $both = $this->makeProvider('both', [ProviderCapability::Source, ProviderCapability::Destination]);

        $registry = new ProviderRegistry([$source, $dest, $both]);
        $destinations = $registry->getDestinations();

        self::assertCount(2, $destinations);
        self::assertArrayHasKey('dest', $destinations);
        self::assertArrayHasKey('both', $destinations);
        self::assertArrayNotHasKey('source', $destinations);
    }

    public function testEmptyRegistryReturnsEmptyArrays(): void
    {
        $registry = new ProviderRegistry([]);

        self::assertCount(0, $registry->all());
        self::assertCount(0, $registry->getSources());
        self::assertCount(0, $registry->getDestinations());
    }

    /**
     * @param ProviderCapability[] $capabilities
     */
    private function makeProvider(string $name, array $capabilities): ProviderInterface
    {
        $provider = m::mock(ProviderInterface::class);
        $provider->shouldReceive('getName')->andReturn($name);
        $provider->shouldReceive('getCapabilities')->andReturn($capabilities);

        return $provider;
    }
}
