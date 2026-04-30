<?php

namespace App\Tests\Controller;

use App\Controller\VerifyEmailController;
use App\Entity\User;
use App\Form\SetPasswordType;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Mockery as m;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use SymfonyCasts\Bundle\VerifyEmail\Exception\InvalidSignatureException;
use SymfonyCasts\Bundle\VerifyEmail\VerifyEmailHelperInterface;

class VerifyEmailControllerTest extends AbstractControllerTestCase
{
    private VerifyEmailHelperInterface|m\LegacyMockInterface $verifyEmailHelper;
    private UserRepository|m\LegacyMockInterface $userRepository;
    private EntityManagerInterface|m\LegacyMockInterface $entityManager;
    private UserPasswordHasherInterface|m\LegacyMockInterface $passwordHasher;

    private VerifyEmailController $controller;

    protected function setUp(): void
    {
        $this->verifyEmailHelper = m::mock(VerifyEmailHelperInterface::class);
        $this->userRepository = m::mock(UserRepository::class);
        $this->entityManager = m::mock(EntityManagerInterface::class);
        $this->passwordHasher = m::mock(UserPasswordHasherInterface::class);

        $this->controller = new VerifyEmailController(
            $this->verifyEmailHelper,
            $this->userRepository,
            $this->entityManager,
            $this->passwordHasher,
        );

        $this->controller->setContainer($this->buildBaseContainer());
    }

    public function testVerifyShowsExpiredWhenIdMissing(): void
    {
        $captured = [];
        $this->expectRender('verify_email/expired.html.twig', function (array $params) use (&$captured): bool {
            $captured = $params;

            return true;
        });

        $response = $this->controller->verify(new Request());

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('No user ID', $captured['message']);
    }

    public function testVerifyShowsExpiredWhenUserNotFound(): void
    {
        $this->userRepository->shouldReceive('find')->with('missing-id')->andReturn(null);

        $captured = [];
        $this->expectRender('verify_email/expired.html.twig', function (array $params) use (&$captured): bool {
            $captured = $params;

            return true;
        });

        $request = new Request(['id' => 'missing-id']);

        $response = $this->controller->verify($request);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('User not found', $captured['message']);
    }

    public function testVerifyRedirectsToLoginIfAlreadyVerified(): void
    {
        $user = $this->makeUser();
        $user->setIsVerified(true);

        $this->userRepository->shouldReceive('find')->andReturn($user);
        $this->expectRoute('app_login', '/login');

        $response = $this->controller->verify(new Request(['id' => 'x']));

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertNotEmpty($this->flashes('info'));
    }

    public function testVerifyShowsExpiredOnInvalidSignature(): void
    {
        $user = $this->makeUser();
        $this->userRepository->shouldReceive('find')->andReturn($user);
        $this->verifyEmailHelper->shouldReceive('validateEmailConfirmationFromRequest')
            ->andThrow(new InvalidSignatureException());

        $captured = [];
        $this->expectRender('verify_email/expired.html.twig', function (array $params) use (&$captured): bool {
            $captured = $params;

            return true;
        });

        $response = $this->controller->verify(new Request(['id' => 'x']));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('expired or is invalid', $captured['message']);
    }

    public function testVerifyShowsSetPasswordFormOnValidLink(): void
    {
        $user = $this->makeUser();
        $this->userRepository->shouldReceive('find')->andReturn($user);
        $this->verifyEmailHelper->shouldReceive('validateEmailConfirmationFromRequest');

        $tokenField = m::mock(FormInterface::class);
        $tokenField->shouldReceive('setData')->once();

        $form = m::mock(FormInterface::class);
        $form->shouldReceive('get')->with('token')->andReturn($tokenField);
        $form->shouldReceive('createView')->andReturn(new FormView());
        // AbstractController::doRender re-checks these to set HTTP 422 on invalid forms
        $form->shouldReceive('isSubmitted')->andReturn(false);
        $form->shouldReceive('isValid')->andReturn(true);

        $this->formFactory->shouldReceive('create')->with(SetPasswordType::class, m::any(), m::any())->andReturn($form);

        $captured = [];
        $this->expectRender('verify_email/verify.html.twig', function (array $params) use (&$captured): bool {
            $captured = $params;

            return true;
        });

        $response = $this->controller->verify(new Request(['id' => 'x']));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame($user, $captured['user']);
    }

    public function testCompleteShowsExpiredWhenTokenMissing(): void
    {
        $tokenField = m::mock(FormInterface::class);
        $tokenField->shouldReceive('getData')->andReturn('');
        $form = m::mock(FormInterface::class);
        $form->shouldReceive('handleRequest');
        $form->shouldReceive('get')->with('token')->andReturn($tokenField);

        $this->formFactory->shouldReceive('create')->andReturn($form);

        $this->expectRender('verify_email/expired.html.twig');

        $response = $this->controller->complete(new Request());

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testCompleteShowsExpiredWhenUserIdMissingFromUrl(): void
    {
        $tokenField = m::mock(FormInterface::class);
        // URL with no `id` query param
        $tokenField->shouldReceive('getData')->andReturn('https://example.com/verify-email?expires=1&signature=abc');
        $form = m::mock(FormInterface::class);
        $form->shouldReceive('handleRequest');
        $form->shouldReceive('get')->with('token')->andReturn($tokenField);

        $this->formFactory->shouldReceive('create')->andReturn($form);

        $captured = [];
        $this->expectRender('verify_email/expired.html.twig', function (array $params) use (&$captured): bool {
            $captured = $params;

            return true;
        });

        $this->controller->complete(new Request());

        $this->assertStringContainsString('Invalid verification token', $captured['message']);
    }

    public function testCompleteHashesPasswordAndMarksUserVerifiedOnSuccess(): void
    {
        $user = $this->makeUser();
        $this->userRepository->shouldReceive('find')->with('user-id')->andReturn($user);

        $tokenField = m::mock(FormInterface::class);
        $tokenField->shouldReceive('getData')->andReturn('https://example.com/verify-email?id=user-id&expires=1&signature=abc');
        $tokenField->shouldReceive('setData');

        $passwordField = m::mock(FormInterface::class);
        $passwordField->shouldReceive('getData')->andReturn('s3cr3tp4ss');

        $form = m::mock(FormInterface::class);
        $form->shouldReceive('handleRequest');
        $form->shouldReceive('isSubmitted')->andReturn(true);
        $form->shouldReceive('isValid')->andReturn(true);
        $form->shouldReceive('get')->with('token')->andReturn($tokenField);
        $form->shouldReceive('get')->with('plainPassword')->andReturn($passwordField);

        $this->formFactory->shouldReceive('create')->andReturn($form);

        $this->verifyEmailHelper->shouldReceive('validateEmailConfirmationFromRequest');
        $this->passwordHasher->shouldReceive('hashPassword')
            ->with($user, 's3cr3tp4ss')
            ->andReturn('hashed-password');
        $this->entityManager->shouldReceive('flush')->once();

        $this->expectRoute('app_login', '/login');

        $response = $this->controller->complete(new Request());

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertSame('hashed-password', $user->getPassword());
        $this->assertTrue($user->isVerified());
        $this->assertNotEmpty($this->flashes('success'));
    }

    public function testCompleteShowsExpiredOnInvalidSignature(): void
    {
        $user = $this->makeUser();
        $this->userRepository->shouldReceive('find')->andReturn($user);

        $tokenField = m::mock(FormInterface::class);
        $tokenField->shouldReceive('getData')->andReturn('https://example.com/verify-email?id=user-id');

        $form = m::mock(FormInterface::class);
        $form->shouldReceive('handleRequest');
        $form->shouldReceive('get')->with('token')->andReturn($tokenField);

        $this->formFactory->shouldReceive('create')->andReturn($form);

        $this->verifyEmailHelper->shouldReceive('validateEmailConfirmationFromRequest')
            ->andThrow(new InvalidSignatureException());

        $captured = [];
        $this->expectRender('verify_email/expired.html.twig', function (array $params) use (&$captured): bool {
            $captured = $params;

            return true;
        });

        $this->controller->complete(new Request());

        $this->assertStringContainsString('expired or is invalid', $captured['message']);
    }

    public function testCompleteRendersFormWhenSubmittedButInvalid(): void
    {
        $user = $this->makeUser();
        $this->userRepository->shouldReceive('find')->andReturn($user);

        $tokenField = m::mock(FormInterface::class);
        $tokenField->shouldReceive('getData')->andReturn('https://example.com/verify-email?id=user-id');
        $tokenField->shouldReceive('setData');

        $form = m::mock(FormInterface::class);
        $form->shouldReceive('handleRequest');
        $form->shouldReceive('isSubmitted')->andReturn(true);
        $form->shouldReceive('isValid')->andReturn(false);
        $form->shouldReceive('get')->with('token')->andReturn($tokenField);
        $form->shouldReceive('createView')->andReturn(new FormView());

        $this->formFactory->shouldReceive('create')->andReturn($form);

        $this->verifyEmailHelper->shouldReceive('validateEmailConfirmationFromRequest');

        $captured = [];
        $this->expectRender('verify_email/verify.html.twig', function (array $params) use (&$captured): bool {
            $captured = $params;

            return true;
        });

        $this->controller->complete(new Request());

        $this->assertSame($user, $captured['user']);
    }

    private function makeUser(): User
    {
        $u = new User();
        $u->setEmail('me@example.com');
        $u->setFirstName('A');
        $u->setLastName('B');

        return $u;
    }
}
