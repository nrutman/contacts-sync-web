<?php

namespace App\Tests\Command;

use App\Client\Provider\OAuthProviderInterface;
use App\Client\Provider\ProviderInterface;
use App\Client\Provider\ProviderRegistry;
use App\Command\ConfigureSyncCommand;
use App\Entity\Organization;
use App\Entity\ProviderCredential;
use App\Repository\ProviderCredentialRepository;
use Doctrine\ORM\EntityManagerInterface;
use Mockery\Adapter\Phpunit\MockeryTestCase;
use Mockery as m;
use Symfony\Component\Console\Tester\CommandTester;

class ConfigureSyncCommandTest extends MockeryTestCase
{
    private const AUTH_URL = 'https://accounts.google.com/auth';
    private const AUTH_CODE = 'test-auth-code';
    private const TOKEN_DATA = ['access_token' => 'test-token', 'refresh_token' => 'test-refresh'];

    private ProviderRegistry|m\LegacyMockInterface $providerRegistry;
    private ProviderCredentialRepository|m\LegacyMockInterface $credentialRepository;
    private EntityManagerInterface|m\LegacyMockInterface $entityManager;
    private Organization $organization;
    private ProviderCredential $credential;

    public function setUp(): void
    {
        $this->providerRegistry = m::mock(ProviderRegistry::class);
        $this->credentialRepository = m::mock(ProviderCredentialRepository::class);
        $this->entityManager = m::mock(EntityManagerInterface::class);

        $this->organization = new Organization();
        $this->organization->setName('Test Org');

        $this->credential = new ProviderCredential();
        $this->credential->setOrganization($this->organization);
        $this->credential->setProviderName('google_groups');
        $this->credential->setLabel('Main Google');
        $this->credential->setCredentialsArray([
            'oauth_credentials' => '{"web":{}}',
            'domain' => 'example.com',
        ]);
    }

    public function testExecuteNoCredentialsFound(): void
    {
        $this->credentialRepository
            ->shouldReceive('findAll')
            ->andReturn([]);

        $tester = $this->executeCommand();

        self::assertEquals(1, $tester->getStatusCode());
        self::assertStringContainsString(
            'No provider credentials found',
            $tester->getDisplay(),
        );
    }

    public function testExecuteAlreadyConfigured(): void
    {
        $this->credential->setCredentialsArray([
            'oauth_credentials' => '{"web":{}}',
            'domain' => 'example.com',
            'token' => '{"access_token":"test"}',
        ]);

        $this->credentialRepository
            ->shouldReceive('findAll')
            ->andReturn([$this->credential]);

        $provider = $this->makeOAuthProvider();

        $this->providerRegistry
            ->shouldReceive('get')
            ->with('google_groups')
            ->andReturn($provider);

        $tester = $this->executeCommand();

        self::assertEquals(0, $tester->getStatusCode());
        self::assertStringContainsString(
            'already configured',
            $tester->getDisplay(),
        );
    }

    public function testExecuteConfiguresWithAuthCode(): void
    {
        $this->credentialRepository
            ->shouldReceive('findAll')
            ->andReturn([$this->credential]);

        $provider = $this->makeOAuthProvider();

        $provider
            ->shouldReceive('getOAuthStartUrl')
            ->once()
            ->andReturn(self::AUTH_URL);

        $provider
            ->shouldReceive('handleOAuthCallback')
            ->once()
            ->with($this->credential, self::AUTH_CODE, 'urn:ietf:wg:oauth:2.0:oob')
            ->andReturn([
                'oauth_credentials' => '{"web":{}}',
                'domain' => 'example.com',
                'token' => json_encode(self::TOKEN_DATA),
            ]);

        $this->providerRegistry
            ->shouldReceive('get')
            ->with('google_groups')
            ->andReturn($provider);

        $this->entityManager->shouldReceive('flush')->once();

        $tester = $this->executeCommand([], [self::AUTH_CODE]);

        self::assertEquals(0, $tester->getStatusCode());
        self::assertStringContainsString(self::AUTH_URL, $tester->getDisplay());
        self::assertStringContainsString(
            'configured successfully',
            $tester->getDisplay(),
        );
    }

    public function testExecuteEmptyAuthCode(): void
    {
        $this->credentialRepository
            ->shouldReceive('findAll')
            ->andReturn([$this->credential]);

        $provider = $this->makeOAuthProvider();

        $provider
            ->shouldReceive('getOAuthStartUrl')
            ->once()
            ->andReturn(self::AUTH_URL);

        $this->providerRegistry
            ->shouldReceive('get')
            ->with('google_groups')
            ->andReturn($provider);

        $tester = $this->executeCommand([], ['']);

        self::assertEquals(1, $tester->getStatusCode());
        self::assertStringContainsString(
            'authentication code must be provided',
            $tester->getDisplay(),
        );
    }

    public function testExecuteSkipsNonOAuthProviders(): void
    {
        $pcCredential = new ProviderCredential();
        $pcCredential->setOrganization($this->organization);
        $pcCredential->setProviderName('planning_center');
        $pcCredential->setCredentialsArray(['app_id' => 'id', 'app_secret' => 'secret']);

        $this->credentialRepository
            ->shouldReceive('findAll')
            ->andReturn([$pcCredential]);

        $nonOAuthProvider = m::mock(ProviderInterface::class);
        $nonOAuthProvider->shouldReceive('getDisplayName')->andReturn('Planning Center');

        $this->providerRegistry
            ->shouldReceive('get')
            ->with('planning_center')
            ->andReturn($nonOAuthProvider);

        $tester = $this->executeCommand();

        self::assertEquals(0, $tester->getStatusCode());
        self::assertStringContainsString('No OAuth-requiring', $tester->getDisplay());
    }

    private function makeOAuthProvider(): m\LegacyMockInterface|ProviderInterface|OAuthProviderInterface
    {
        $provider = m::mock(ProviderInterface::class, OAuthProviderInterface::class);
        $provider->shouldReceive('getDisplayName')->andReturn('Google Groups');

        return $provider;
    }

    private function executeCommand(
        array $options = [],
        array $inputs = [],
    ): CommandTester {
        $command = new ConfigureSyncCommand(
            $this->providerRegistry,
            $this->credentialRepository,
            $this->entityManager,
        );

        $tester = new CommandTester($command);

        if (count($inputs) > 0) {
            $tester->setInputs($inputs);
        }

        $tester->execute($options);

        return $tester;
    }
}
