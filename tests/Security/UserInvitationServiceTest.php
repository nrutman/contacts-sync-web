<?php

namespace App\Tests\Security;

use App\Entity\User;
use App\Security\UserInvitationService;
use Mockery as m;
use Mockery\Adapter\Phpunit\MockeryTestCase;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use SymfonyCasts\Bundle\VerifyEmail\Model\VerifyEmailSignatureComponents;
use SymfonyCasts\Bundle\VerifyEmail\VerifyEmailHelperInterface;
use Twig\Environment;

class UserInvitationServiceTest extends MockeryTestCase
{
    private VerifyEmailHelperInterface|m\MockInterface $verifyEmailHelper;
    private MailerInterface|m\MockInterface $mailer;
    private Environment|m\MockInterface $twig;
    private UserInvitationService $service;

    protected function setUp(): void
    {
        $this->verifyEmailHelper = m::mock(VerifyEmailHelperInterface::class);
        $this->mailer = m::mock(MailerInterface::class);
        $this->twig = m::mock(Environment::class);

        $this->service = new UserInvitationService(
            $this->verifyEmailHelper,
            $this->mailer,
            $this->twig,
        );
    }

    private function createSignatureComponents(
        string $signedUrl,
    ): VerifyEmailSignatureComponents {
        $expiresAt = new \DateTimeImmutable('+1 hour');
        $generatedAt = time();

        return new VerifyEmailSignatureComponents(
            $expiresAt,
            $signedUrl,
            $generatedAt,
        );
    }

    public function testSendInvitationGeneratesSignatureAndSendsEmail(): void
    {
        $user = new User();
        $user->setEmail('jane@example.com');
        $user->setFirstName('Jane');
        $user->setLastName('Doe');

        $signedUrl =
            'https://example.com/verify-email?signature=abc123&id='.
            $user->getId();
        $signatureComponents = $this->createSignatureComponents($signedUrl);

        $this->verifyEmailHelper
            ->shouldReceive('generateSignature')
            ->once()
            ->with(
                'app_verify_email',
                (string) $user->getId(),
                'jane@example.com',
                ['id' => (string) $user->getId()],
            )
            ->andReturn($signatureComponents);

        $renderedHtml = '<html><body>Welcome Jane!</body></html>';

        $this->twig
            ->shouldReceive('render')
            ->once()
            ->with(
                'email/user_invitation.html.twig',
                m::on(function (array $context) use ($user, $signedUrl) {
                    return $context['user'] === $user
                        && $context['signedUrl'] === $signedUrl;
                }),
            )
            ->andReturn($renderedHtml);

        $this->mailer
            ->shouldReceive('send')
            ->once()
            ->with(
                m::on(function (Email $email) use ($renderedHtml) {
                    $toAddresses = $email->getTo();
                    if (
                        count($toAddresses) !== 1
                        || $toAddresses[0]->getAddress() !== 'jane@example.com'
                    ) {
                        return false;
                    }

                    if (
                        $email->getSubject() !==
                        'You\'ve been invited to Contacts Sync'
                    ) {
                        return false;
                    }

                    if ($email->getHtmlBody() !== $renderedHtml) {
                        return false;
                    }

                    return true;
                }),
            );

        $this->service->sendInvitation($user);
    }

    public function testSendInvitationUsesCorrectRouteAndUserId(): void
    {
        $user = new User();
        $user->setEmail('bob@example.com');
        $user->setFirstName('Bob');
        $user->setLastName('Smith');

        $userId = (string) $user->getId();
        $signedUrl = 'https://example.com/verify-email?id='.$userId;
        $signatureComponents = $this->createSignatureComponents($signedUrl);

        $this->verifyEmailHelper
            ->shouldReceive('generateSignature')
            ->once()
            ->with('app_verify_email', $userId, 'bob@example.com', ['id' => $userId])
            ->andReturn($signatureComponents);

        $this->twig
            ->shouldReceive('render')
            ->once()
            ->andReturn('<html></html>');

        $this->mailer
            ->shouldReceive('send')
            ->once()
            ->with(m::type(Email::class));

        $this->service->sendInvitation($user);
    }

    public function testSendInvitationPropagatesMailerException(): void
    {
        $user = new User();
        $user->setEmail('fail@example.com');
        $user->setFirstName('Fail');
        $user->setLastName('User');

        $signatureComponents = $this->createSignatureComponents(
            'https://example.com/verify',
        );

        $this->verifyEmailHelper
            ->shouldReceive('generateSignature')
            ->andReturn($signatureComponents);

        $this->twig->shouldReceive('render')->andReturn('<html></html>');

        $this->mailer
            ->shouldReceive('send')
            ->once()
            ->andThrow(new \RuntimeException('SMTP connection failed'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('SMTP connection failed');

        $this->service->sendInvitation($user);
    }
}
