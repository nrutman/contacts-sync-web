<?php

namespace App\Tests\Client;

use App\Client\WebClientFactory;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Mockery\Adapter\Phpunit\MockeryTestCase;

class WebClientFactoryTest extends MockeryTestCase
{
    public function testRetriesOn429AndReturnsEventualSuccess(): void
    {
        $mock = new MockHandler([
            new Response(429),
            new Response(429),
            new Response(200, [], 'ok'),
        ]);

        $client = $this->createClient($mock);

        $response = $client->request('GET', 'https://example.test/');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('ok', (string) $response->getBody());
    }

    public function testStopsRetryingAfterMaxAttempts(): void
    {
        $mock = new MockHandler([
            new Response(429),
            new Response(429),
            new Response(429),
            new Response(429),
        ]);

        $client = $this->createClient($mock);

        $response = $client->request(
            'GET',
            'https://example.test/',
            ['http_errors' => false],
        );

        $this->assertSame(429, $response->getStatusCode());
        // Initial attempt + 3 retries = 4 responses consumed; 0 left in queue.
        $this->assertSame(0, $mock->count());
    }

    public function testDoesNotRetryOnSuccess(): void
    {
        $mock = new MockHandler([
            new Response(200, [], 'ok'),
            new Response(500),
        ]);

        $client = $this->createClient($mock);

        $response = $client->request('GET', 'https://example.test/');

        $this->assertSame(200, $response->getStatusCode());
        // The 500 should still be queued — no retry happened.
        $this->assertSame(1, $mock->count());
    }

    public function testDoesNotRetryOnNon429Errors(): void
    {
        $mock = new MockHandler([
            new Response(500),
            new Response(200),
        ]);

        $client = $this->createClient($mock);

        $response = $client->request(
            'GET',
            'https://example.test/',
            ['http_errors' => false],
        );

        $this->assertSame(500, $response->getStatusCode());
        // The 200 should still be queued — no retry happened.
        $this->assertSame(1, $mock->count());
    }

    public function testHonorsNumericRetryAfterHeader(): void
    {
        $mock = new MockHandler([
            new Response(429, ['Retry-After' => '0'], 'first'),
            new Response(200, [], 'second'),
        ]);

        $client = $this->createClient($mock);

        $start = microtime(true);
        $response = $client->request('GET', 'https://example.test/');
        $elapsed = microtime(true) - $start;

        $this->assertSame(200, $response->getStatusCode());
        // Retry-After: 0 means no delay — the request should complete fast.
        $this->assertLessThan(1.0, $elapsed);
    }

    private function createClient(MockHandler $mock): \GuzzleHttp\ClientInterface
    {
        $factory = new WebClientFactory(
            defaultConfiguration: [],
            retryOptions: [
                'max_retry_attempts' => 3,
                'retry_on_status' => [429],
                // Use a tiny base delay so tests don't wait seconds.
                'default_retry_multiplier' => 0.001,
            ],
        );

        return $factory->create([
            'handler' => HandlerStack::create($mock),
        ]);
    }
}
