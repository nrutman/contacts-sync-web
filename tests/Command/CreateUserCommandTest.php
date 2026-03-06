<?php

namespace App\Tests\Command;

use App\Command\CreateUserCommand;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Mockery\Adapter\Phpunit\MockeryTestCase;
use Mockery as m;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class CreateUserCommandTest extends MockeryTestCase
{
    private EntityManagerInterface|m\MockInterface $entityManager;
    private UserPasswordHasherInterface|m\MockInterface $passwordHasher;
    private EntityRepository|m\MockInterface $userRepository;

    protected function setUp(): void
    {
        $this->entityManager = m::mock(EntityManagerInterface::class);
        $this->passwordHasher = m::mock(UserPasswordHasherInterface::class);
        $this->userRepository = m::mock(EntityRepository::class);

        $this->entityManager
            ->shouldReceive('getRepository')
            ->with(User::class)
            ->andReturn($this->userRepository)
            ->byDefault();
    }

    public function testCreateUserSuccessfully(): void
    {
        $this->userRepository
            ->shouldReceive('findOneBy')
            ->with(['email' => 'john@example.com'])
            ->andReturn(null);

        $this->passwordHasher
            ->shouldReceive('hashPassword')
            ->once()
            ->withArgs(function (User $user, string $plainPassword) {
                return $plainPassword === 'securepassword123';
            })
            ->andReturn('$2y$13$hashed_value');

        $this->entityManager
            ->shouldReceive('persist')
            ->once()
            ->withArgs(function (User $user) {
                return $user->getEmail() === 'john@example.com'
                    && $user->getFirstName() === 'John'
                    && $user->getLastName() === 'Doe'
                    && $user->isVerified() === true
                    && $user->getPassword() === '$2y$13$hashed_value'
                    && $user->getRoles() === ['ROLE_USER'];
            });

        $this->entityManager
            ->shouldReceive('flush')
            ->once();

        $tester = $this->executeCommand(
            inputs: ['john@example.com', 'John', 'Doe', 'securepassword123', 'securepassword123'],
        );

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('John Doe', $tester->getDisplay());
        self::assertStringContainsString('john@example.com', $tester->getDisplay());
        self::assertStringContainsString('created successfully', $tester->getDisplay());
    }

    public function testCreateUserWithAdminRole(): void
    {
        $this->userRepository
            ->shouldReceive('findOneBy')
            ->with(['email' => 'admin@example.com'])
            ->andReturn(null);

        $this->passwordHasher
            ->shouldReceive('hashPassword')
            ->once()
            ->andReturn('$2y$13$hashed_value');

        $this->entityManager
            ->shouldReceive('persist')
            ->once()
            ->withArgs(function (User $user) {
                return in_array('ROLE_ADMIN', $user->getRoles(), true)
                    && in_array('ROLE_USER', $user->getRoles(), true);
            });

        $this->entityManager
            ->shouldReceive('flush')
            ->once();

        $tester = $this->executeCommand(
            inputs: ['admin@example.com', 'Admin', 'User', 'securepassword123', 'securepassword123'],
            options: ['--admin' => true],
        );

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('ROLE_ADMIN', $tester->getDisplay());
    }

    public function testEmptyEmailFails(): void
    {
        $tester = $this->executeCommand(
            inputs: ['', 'John', 'Doe', 'securepassword123', 'securepassword123'],
        );

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('Email is required', $tester->getDisplay());
    }

    public function testInvalidEmailFails(): void
    {
        $tester = $this->executeCommand(
            inputs: ['not-an-email', 'John', 'Doe', 'securepassword123', 'securepassword123'],
        );

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('not a valid email', $tester->getDisplay());
    }

    public function testDuplicateEmailFails(): void
    {
        $existingUser = new User();
        $existingUser->setEmail('existing@example.com');
        $existingUser->setFirstName('Existing');
        $existingUser->setLastName('User');

        $this->userRepository
            ->shouldReceive('findOneBy')
            ->with(['email' => 'existing@example.com'])
            ->andReturn($existingUser);

        $tester = $this->executeCommand(
            inputs: ['existing@example.com', 'John', 'Doe', 'securepassword123', 'securepassword123'],
        );

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('already exists', $tester->getDisplay());
    }

    public function testEmptyFirstNameFails(): void
    {
        $this->userRepository
            ->shouldReceive('findOneBy')
            ->with(['email' => 'john@example.com'])
            ->andReturn(null);

        $tester = $this->executeCommand(
            inputs: ['john@example.com', '', 'Doe', 'securepassword123', 'securepassword123'],
        );

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('First name is required', $tester->getDisplay());
    }

    public function testEmptyLastNameFails(): void
    {
        $this->userRepository
            ->shouldReceive('findOneBy')
            ->with(['email' => 'john@example.com'])
            ->andReturn(null);

        $tester = $this->executeCommand(
            inputs: ['john@example.com', 'John', '', 'securepassword123', 'securepassword123'],
        );

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('Last name is required', $tester->getDisplay());
    }

    public function testShortPasswordFails(): void
    {
        $this->userRepository
            ->shouldReceive('findOneBy')
            ->with(['email' => 'john@example.com'])
            ->andReturn(null);

        $tester = $this->executeCommand(
            inputs: ['john@example.com', 'John', 'Doe', 'short', 'short'],
        );

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('at least 8 characters', $tester->getDisplay());
    }

    public function testPasswordMismatchFails(): void
    {
        $this->userRepository
            ->shouldReceive('findOneBy')
            ->with(['email' => 'john@example.com'])
            ->andReturn(null);

        $tester = $this->executeCommand(
            inputs: ['john@example.com', 'John', 'Doe', 'securepassword123', 'differentpassword'],
        );

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('do not match', $tester->getDisplay());
    }

    public function testUserIsAutoVerified(): void
    {
        $this->userRepository
            ->shouldReceive('findOneBy')
            ->andReturn(null);

        $this->passwordHasher
            ->shouldReceive('hashPassword')
            ->andReturn('$2y$13$hashed');

        $this->entityManager
            ->shouldReceive('persist')
            ->once()
            ->withArgs(function (User $user) {
                return $user->isVerified() === true;
            });

        $this->entityManager
            ->shouldReceive('flush')
            ->once();

        $tester = $this->executeCommand(
            inputs: ['test@example.com', 'Test', 'User', 'securepassword123', 'securepassword123'],
        );

        self::assertSame(0, $tester->getStatusCode());
    }

    public function testNonAdminUserHasOnlyRoleUser(): void
    {
        $this->userRepository
            ->shouldReceive('findOneBy')
            ->andReturn(null);

        $this->passwordHasher
            ->shouldReceive('hashPassword')
            ->andReturn('$2y$13$hashed');

        $persistedUser = null;
        $this->entityManager
            ->shouldReceive('persist')
            ->once()
            ->withArgs(function (User $user) use (&$persistedUser) {
                $persistedUser = $user;

                return true;
            });

        $this->entityManager
            ->shouldReceive('flush')
            ->once();

        $tester = $this->executeCommand(
            inputs: ['test@example.com', 'Test', 'User', 'securepassword123', 'securepassword123'],
        );

        self::assertSame(0, $tester->getStatusCode());
        self::assertNotNull($persistedUser);
        self::assertSame(['ROLE_USER'], $persistedUser->getRoles());
        self::assertStringNotContainsString('ROLE_ADMIN', $tester->getDisplay());
    }

    private function executeCommand(array $inputs = [], array $options = []): CommandTester
    {
        $command = new CreateUserCommand(
            $this->entityManager,
            $this->passwordHasher,
        );

        $tester = new CommandTester($command);
        $tester->setInputs($inputs);
        $tester->execute($options);

        return $tester;
    }
}
