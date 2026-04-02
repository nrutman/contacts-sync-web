<?php

namespace App\Security;

use App\Entity\User;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use SymfonyCasts\Bundle\VerifyEmail\VerifyEmailHelperInterface;
use Twig\Environment;

class UserInvitationService
{
    public function __construct(
        private readonly VerifyEmailHelperInterface $verifyEmailHelper,
        private readonly MailerInterface $mailer,
        private readonly Environment $twig,
    ) {
    }

    public function sendInvitation(User $user): void
    {
        $signatureComponents = $this->verifyEmailHelper->generateSignature(
            'app_verify_email',
            (string) $user->getId(),
            $user->getEmail(),
            ['id' => (string) $user->getId()],
        );

        $html = $this->twig->render('email/user_invitation.html.twig', [
            'user' => $user,
            'signedUrl' => $signatureComponents->getSignedUrl(),
            'expiresAtMessageKey' => $signatureComponents->getExpirationMessageKey(),
            'expiresAtMessageData' => $signatureComponents->getExpirationMessageData(),
        ]);

        $email = (new Email())
            ->to($user->getEmail())
            ->subject('You\'ve been invited to Contacts Sync')
            ->html($html);

        $this->mailer->send($email);
    }
}
