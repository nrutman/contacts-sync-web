<?php

namespace App\Client;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\HandlerStack;
use GuzzleRetry\GuzzleRetryMiddleware;

class WebClientFactory implements WebClientFactoryInterface
{
    private const DEFAULT_RETRY_OPTIONS = [
        'max_retry_attempts' => 3,
        'retry_on_status' => [429],
    ];

    /**
     * @param array $defaultConfiguration default configuration for creating Guzzle clients
     * @param array $retryOptions options passed to GuzzleRetryMiddleware
     *                            (see https://github.com/caseyamcl/guzzle_retry_middleware)
     */
    public function __construct(
        protected array $defaultConfiguration = [],
        private readonly array $retryOptions = self::DEFAULT_RETRY_OPTIONS,
    ) {
    }

    /**
     * Creates a new Guzzle client with retry-on-429 middleware. The middleware
     * honors the `Retry-After` response header when present.
     *
     * @param array $guzzleConfiguration Configuration for the Guzzle Client
     *
     * @return ClientInterface The instantiated Guzzle Client
     */
    public function create(array $guzzleConfiguration = []): ClientInterface
    {
        $config = array_merge($guzzleConfiguration, $this->defaultConfiguration);

        $handler = $config['handler'] ?? HandlerStack::create();
        $handler->push(GuzzleRetryMiddleware::factory($this->retryOptions));
        $config['handler'] = $handler;

        return new Client($config);
    }
}
