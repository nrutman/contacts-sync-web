<?php

namespace App\Tests\Command;

use App\Client\Google\GoogleClient;
use App\Client\Google\GoogleClientFactory;
use App\Client\Google\InvalidGoogleTokenException;
use App\Command\ConfigureSyncCommand;
use App\Entity\Organization;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Mockery\Adapter\Phpunit\MockeryTestCase;
use Mockery as m;
use Symfony\Component\Console\Tester\CommandTester;

class ConfigureSyncCommandTest extends MockeryTestCase
{
    private const AUTH_URL = 'https://accounts.google.com/auth';
    private const AUTH_CODE = 'test-auth-code';
    private const DOMAIN = 'example.com';
    private const TOKEN_DATA = [
        'access_token' => 'test-token',
        'refresh_token' => 'test-refresh',
    ];

    /** @var GoogleClientFactory|m\LegacyMockInterface|m\MockInterface */
    private $googleClientFactory;

    /** @var GoogleClient|m\LegacyMockInterface|m\MockInterface */
    private $googleClient;

    /** @var EntityManagerInterface|m\LegacyMockInterface|m\MockInterface */
    private $entityManager;

    /** @var EntityRepository|m\LegacyMockInterface|m\MockInterface */
    private $organizationRepository;

    private Organization $organization;

    public function setUp(): void
    {
        $this->googleClientFactory = m::mock(GoogleClientFactory::class);
        $this->googleClient = m::mock(GoogleClient::class);
        $this->entityManager = m::mock(EntityManagerInterface::class);
        $this->organizationRepository = m::mock(EntityRepository::class);

        $this->organization = new Organization();
        $this->organization->setName('Test Org');
        $this->organization->setPlanningCenterAppId('pc-id');
        $this->organization->setPlanningCenterAppSecret('pc-secret');
        $this->organization->setGoogleOAuthCredentials('{}');
        $this->organization->setGoogleDomain(self::DOMAIN);

        $this->entityManager
            ->shouldReceive('getRepository')
            ->with(Organization::class)
            ->andReturn($this->organizationRepository);
    }

    public function testExecuteNoOrganizationFound(): void
    {
        $this->organizationRepository
            ->shouldReceive('findOneBy')
            ->with([])
            ->andReturn(null);

        $tester = $this->executeCommand();

        self::assertEquals(1, $tester->getStatusCode());
        self::assertStringContainsString(
            'No organization found',
            $tester->getDisplay(),
        );
    }

    public function testExecuteAlreadyConfigured(): void
    {
        $this->organizationRepository
            ->shouldReceive('findOneBy')
            ->with([])
            ->andReturn($this->organization);

        $this->googleClientFactory
            ->shouldReceive('create')
            ->with($this->organization)
            ->andReturn($this->googleClient);

        $this->googleClient
            ->shouldReceive('initialize')
            ->once()
            ->andReturn($this->googleClient);

        $tester = $this->executeCommand();

        self::assertEquals(0, $tester->getStatusCode());
        self::assertStringContainsString(
            'already configured',
            $tester->getDisplay(),
        );
    }

    public function testExecuteAlreadyConfiguredWithForce(): void
    {
        $this->organizationRepository
            ->shouldReceive('findOneBy')
            ->with([])
            ->andReturn($this->organization);

        $this->googleClientFactory
            ->shouldReceive('create')
            ->with($this->organization)
            ->andReturn($this->googleClient);

        $this->googleClient
            ->shouldReceive('initialize')
            ->once()
            ->andReturn($this->googleClient);

        $this->googleClient
            ->shouldReceive('createAuthUrl')
            ->once()
            ->andReturn(self::AUTH_URL);

        $this->googleClient
            ->shouldReceive('setAuthCode')
            ->once()
            ->with(self::AUTH_CODE);

        $this->googleClient
            ->shouldReceive('getTokenData')
            ->once()
            ->andReturn(self::TOKEN_DATA);

        $this->entityManager->shouldReceive('flush')->once();

        $tester = $this->executeCommand(['--force' => true], [self::AUTH_CODE]);

        self::assertEquals(0, $tester->getStatusCode());
        self::assertStringContainsString(self::AUTH_URL, $tester->getDisplay());
        self::assertStringContainsString(
            'configured successfully',
            $tester->getDisplay(),
        );
    }

    public function testExecuteNotConfiguredProvidesAuthCode(): void
    {
        $this->organizationRepository
            ->shouldReceive('findOneBy')
            ->with([])
            ->andReturn($this->organization);

        $this->googleClientFactory
            ->shouldReceive('create')
            ->with($this->organization)
            ->andReturn($this->googleClient);

        $this->googleClient
            ->shouldReceive('initialize')
            ->once()
            ->andThrow(new InvalidGoogleTokenException());

        $this->googleClient
            ->shouldReceive('createAuthUrl')
            ->once()
            ->andReturn(self::AUTH_URL);

        $this->googleClient
            ->shouldReceive('setAuthCode')
            ->once()
            ->with(self::AUTH_CODE);

        $this->googleClient
            ->shouldReceive('getTokenData')
            ->once()
            ->andReturn(self::TOKEN_DATA);

        $this->entityManager->shouldReceive('flush')->once();

        $tester = $this->executeCommand([], [self::AUTH_CODE]);

        self::assertEquals(0, $tester->getStatusCode());
        self::assertStringContainsString(self::AUTH_URL, $tester->getDisplay());
        self::assertStringContainsString(self::DOMAIN, $tester->getDisplay());
        self::assertStringContainsString(
            'configured successfully',
            $tester->getDisplay(),
        );
    }

    public function testExecuteNotConfiguredEmptyAuthCode(): void
    {
        $this->organizationRepository
            ->shouldReceive('findOneBy')
            ->with([])
            ->andReturn($this->organization);

        $this->googleClientFactory
            ->shouldReceive('create')
            ->with($this->organization)
            ->andReturn($this->googleClient);

        $this->googleClient
            ->shouldReceive('initialize')
            ->once()
            ->andThrow(new InvalidGoogleTokenException());

        $this->googleClient
            ->shouldReceive('createAuthUrl')
            ->once()
            ->andReturn(self::AUTH_URL);

        $tester = $this->executeCommand([], ['']);

        self::assertEquals(1, $tester->getStatusCode());
        self::assertStringContainsString(
            'authentication code must be provided',
            $tester->getDisplay(),
        );
    }

    public function testExecutePersistsTokenToOrganization(): void
    {
        $this->organizationRepository
            ->shouldReceive('findOneBy')
            ->with([])
            ->andReturn($this->organization);

        $this->googleClientFactory
            ->shouldReceive('create')
            ->with($this->organization)
            ->andReturn($this->googleClient);

        $this->googleClient
            ->shouldReceive('initialize')
            ->once()
            ->andThrow(new InvalidGoogleTokenException());

        $this->googleClient
            ->shouldReceive('createAuthUrl')
            ->once()
            ->andReturn(self::AUTH_URL);

        $this->googleClient
            ->shouldReceive('setAuthCode')
            ->once()
            ->with(self::AUTH_CODE);

        $this->googleClient
            ->shouldReceive('getTokenData')
            ->once()
            ->andReturn(self::TOKEN_DATA);

        $this->entityManager->shouldReceive('flush')->once();

        $tester = $this->executeCommand([], [self::AUTH_CODE]);

        self::assertEquals(0, $tester->getStatusCode());
        self::assertEquals(
            json_encode(self::TOKEN_DATA, JSON_THROW_ON_ERROR),
            $this->organization->getGoogleToken(),
        );
    }

    public function testExecuteHandlesNullTokenData(): void
    {
        $this->organizationRepository
            ->shouldReceive('findOneBy')
            ->with([])
            ->andReturn($this->organization);

        $this->googleClientFactory
            ->shouldReceive('create')
            ->with($this->organization)
            ->andReturn($this->googleClient);

        $this->googleClient
            ->shouldReceive('initialize')
            ->once()
            ->andThrow(new InvalidGoogleTokenException());

        $this->googleClient
            ->shouldReceive('createAuthUrl')
            ->once()
            ->andReturn(self::AUTH_URL);

        $this->googleClient
            ->shouldReceive('setAuthCode')
            ->once()
            ->with(self::AUTH_CODE);

        $this->googleClient
            ->shouldReceive('getTokenData')
            ->once()
            ->andReturn(null);

        $tester = $this->executeCommand([], [self::AUTH_CODE]);

        self::assertEquals(0, $tester->getStatusCode());
        self::assertNull($this->organization->getGoogleToken());
    }

    private function executeCommand(
        array $options = [],
        array $inputs = [],
    ): CommandTester {
        $command = new ConfigureSyncCommand(
            $this->googleClientFactory,
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
