<?php

namespace App\Security;

use App\Entity\User;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAccountStatusException;
use Symfony\Component\Security\Core\User\UserCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;

class UserChecker implements UserCheckerInterface
{
    public function checkPreAuth(UserInterface $user): void
    {
        if (!$user instanceof User) {
            return;
        }

        if (!$user->isVerified()) {
            throw new CustomUserMessageAccountStatusException('Your account has not been verified yet. Please check your email for an invitation link.');
        }

        // Defense-in-depth: even if isVerified is somehow true,
        // block login when no password has been set. This guards
        // against bugs, manual DB edits, or race conditions that
        // could leave isVerified = true with a null password.
        if ($user->getPassword() === null || $user->getPassword() === '') {
            throw new CustomUserMessageAccountStatusException('Your account is not fully set up. Please use the invitation link from your email to set a password.');
        }
    }

    public function checkPostAuth(UserInterface $user): void
    {
        // No post-authentication checks needed.
    }
}
