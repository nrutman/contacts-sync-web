<?php

namespace App\Tests\Security;

use App\Entity\User;
use App\Security\UserChecker;
use Mockery\Adapter\Phpunit\MockeryTestCase;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAccountStatusException;
use Symfony\Component\Security\Core\User\UserInterface;

class UserCheckerTest extends MockeryTestCase
{
    private UserChecker $checker;

    protected function setUp(): void
    {
        $this->checker = new UserChecker();
    }

    public function testVerifiedUserWithPasswordPassesPreAuth(): void
    {
        $user = new User();
        $user->setEmail('test@example.com');
        $user->setFirstName('Test');
        $user->setLastName('User');
        $user->setIsVerified(true);
        $user->setPassword('$2y$13$hashed_password_value_here');

        // Should not throw any exception
        $this->checker->checkPreAuth($user);
        $this->addToAssertionCount(1);
    }

    public function testUnverifiedUserThrows(): void
    {
        $user = new User();
        $user->setEmail('unverified@example.com');
        $user->setFirstName('Unverified');
        $user->setLastName('User');
        $user->setIsVerified(false);

        $this->expectException(CustomUserMessageAccountStatusException::class);
        $this->expectExceptionMessage('Your account has not been verified yet. Please check your email for an invitation link.');

        $this->checker->checkPreAuth($user);
    }

    public function testVerifiedUserWithNullPasswordThrows(): void
    {
        $user = new User();
        $user->setEmail('nullpass@example.com');
        $user->setFirstName('Null');
        $user->setLastName('Password');
        $user->setIsVerified(true);
        $user->setPassword(null);

        $this->expectException(CustomUserMessageAccountStatusException::class);
        $this->expectExceptionMessage('Your account is not fully set up. Please use the invitation link from your email to set a password.');

        $this->checker->checkPreAuth($user);
    }

    public function testVerifiedUserWithEmptyStringPasswordThrows(): void
    {
        $user = new User();
        $user->setEmail('emptypass@example.com');
        $user->setFirstName('Empty');
        $user->setLastName('Password');
        $user->setIsVerified(true);
        $user->setPassword('');

        $this->expectException(CustomUserMessageAccountStatusException::class);
        $this->expectExceptionMessage('Your account is not fully set up. Please use the invitation link from your email to set a password.');

        $this->checker->checkPreAuth($user);
    }

    public function testNonUserObjectIsIgnored(): void
    {
        $nonUser = \Mockery::mock(UserInterface::class);

        // Should not throw — non-User objects are silently passed through
        $this->checker->checkPreAuth($nonUser);
        $this->addToAssertionCount(1);
    }

    public function testCheckPostAuthDoesNothing(): void
    {
        $user = new User();
        $user->setEmail('test@example.com');
        $user->setFirstName('Test');
        $user->setLastName('User');

        // Should not throw any exception regardless of state
        $this->checker->checkPostAuth($user);
        $this->addToAssertionCount(1);
    }

    public function testUnverifiedUserWithNullPasswordThrowsVerificationError(): void
    {
        // When both isVerified=false AND password=null, the verification check
        // should trigger first (order matters for user-facing messages)
        $user = new User();
        $user->setEmail('both@example.com');
        $user->setFirstName('Both');
        $user->setLastName('Issues');
        $user->setIsVerified(false);
        $user->setPassword(null);

        $this->expectException(CustomUserMessageAccountStatusException::class);
        $this->expectExceptionMessage('Your account has not been verified yet.');

        $this->checker->checkPreAuth($user);
    }
}
