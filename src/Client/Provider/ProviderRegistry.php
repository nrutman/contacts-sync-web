<?php

namespace App\Client\Provider;

use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

class ProviderRegistry
{
    /** @var array<string, ProviderInterface> */
    private array $providers = [];

    /**
     * @param iterable<ProviderInterface> $providers
     */
    public function __construct(
        #[AutowireIterator('app.provider')]
        iterable $providers,
    ) {
        foreach ($providers as $provider) {
            $this->providers[$provider->getName()] = $provider;
        }
    }

    public function get(string $name): ProviderInterface
    {
        if (!isset($this->providers[$name])) {
            throw new ProviderNotFoundException($name);
        }

        return $this->providers[$name];
    }

    /**
     * @return array<string, ProviderInterface>
     */
    public function all(): array
    {
        return $this->providers;
    }

    /**
     * @return array<string, ProviderInterface>
     */
    public function getSources(): array
    {
        return array_filter(
            $this->providers,
            static fn (ProviderInterface $p) => in_array(ProviderCapability::Source, $p->getCapabilities(), true),
        );
    }

    /**
     * @return array<string, ProviderInterface>
     */
    public function getDestinations(): array
    {
        return array_filter(
            $this->providers,
            static fn (ProviderInterface $p) => in_array(ProviderCapability::Destination, $p->getCapabilities(), true),
        );
    }
}
