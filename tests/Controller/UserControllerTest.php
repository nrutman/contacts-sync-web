<?php

namespace App\Tests\Controller;

use App\Controller\UserController;
use App\Entity\User;
use App\Form\UserType;
use App\Repository\UserRepository;
use App\Security\UserInvitationService;
use Doctrine\ORM\EntityManagerInterface;
use Mockery as m;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Csrf\CsrfToken;

class UserControllerTest extends AbstractControllerTestCase
{
    private UserRepository|m\LegacyMockInterface $userRepository;
    private EntityManagerInterface|m\LegacyMockInterface $entityManager;
    private UserInvitationService|m\LegacyMockInterface $invitationService;

    private UserController $controller;

    protected function setUp(): void
    {
        $this->userRepository = m::mock(UserRepository::class);
        $this->entityManager = m::mock(EntityManagerInterface::class);
        $this->invitationService = m::mock(UserInvitationService::class);

        $this->controller = new UserController(
            $this->userRepository,
            $this->entityManager,
            $this->invitationService,
        );

        $this->controller->setContainer($this->buildBaseContainer());
    }

    public function testIndexRendersUsersList(): void
    {
        $u1 = $this->makeUser('a@example.com');
        $u2 = $this->makeUser('b@example.com');
        $this->userRepository->shouldReceive('findAllOrdered')->andReturn([$u1, $u2]);

        $captured = [];
        $this->expectRender('user/index.html.twig', function (array $params) use (&$captured): bool {
            $captured = $params;

            return true;
        });

        $response = $this->controller->index();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame([$u1, $u2], $captured['users']);
    }

    public function testNewSendsInvitationOnSuccessfulCreate(): void
    {
        $this->mockForm(UserType::class, true, true, populateData: fn (User $u) => $u->setEmail('newbie@example.com'));
        $this->entityManager->shouldReceive('persist')->once();
        $this->entityManager->shouldReceive('flush')->once();
        $this->invitationService->shouldReceive('sendInvitation')->once();
        $this->expectRoute('app_user_index', '/users');

        $response = $this->controller->new(new Request());

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $successFlashes = $this->flashes('success');
        $this->assertNotEmpty($successFlashes);
        $this->assertStringContainsString('newbie@example.com', $successFlashes[0]);
    }

    public function testNewWarnsWhenInvitationFailsButUserStillCreated(): void
    {
        $this->mockForm(UserType::class, true, true, populateData: fn (User $u) => $u->setEmail('x@example.com'));
        $this->entityManager->shouldReceive('persist')->once();
        $this->entityManager->shouldReceive('flush')->once();
        $this->invitationService->shouldReceive('sendInvitation')->andThrow(new \RuntimeException('SMTP error'));
        $this->expectRoute('app_user_index', '/users');

        $response = $this->controller->new(new Request());

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $warnings = $this->flashes('warning');
        $this->assertNotEmpty($warnings);
        $this->assertStringContainsString('SMTP error', $warnings[0]);
    }

    public function testNewRendersFormWhenNotSubmitted(): void
    {
        $this->mockForm(UserType::class, false);
        $this->expectRender('user/new.html.twig');

        $response = $this->controller->new(new Request());

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testEditFlushesOnValidSubmit(): void
    {
        $user = $this->makeUser('edit@example.com');
        $this->mockForm(UserType::class, true, true);
        $this->entityManager->shouldReceive('flush')->once();
        $this->expectRoute('app_user_index', '/users');

        $response = $this->controller->edit(new Request(), $user);

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertNotEmpty($this->flashes('success'));
    }

    public function testDeleteRefusesToDeleteSelf(): void
    {
        $user = $this->makeUser('me@example.com');
        $this->setUser($user);
        $this->expectRoute('app_user_index', '/users');

        $request = Request::create('/users/'.$user->getId(), 'DELETE', ['_token' => 'whatever']);

        $this->entityManager->shouldNotReceive('remove');
        $this->entityManager->shouldNotReceive('flush');

        $response = $this->controller->delete($request, $user);

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertNotEmpty($this->flashes('danger'));
    }

    public function testDeleteWithValidCsrfTokenRemovesUser(): void
    {
        $self = $this->makeUser('me@example.com');
        $other = $this->makeUser('other@example.com');
        $this->setUser($self);

        $this->csrfTokenManager->shouldReceive('isTokenValid')
            ->with(m::on(fn (CsrfToken $t) => $t->getId() === 'delete-user-'.$other->getId() && $t->getValue() === 'good'))
            ->andReturn(true);

        $this->entityManager->shouldReceive('remove')->with($other)->once();
        $this->entityManager->shouldReceive('flush')->once();
        $this->expectRoute('app_user_index', '/users');

        $request = Request::create('/users/'.$other->getId(), 'DELETE', ['_token' => 'good']);

        $response = $this->controller->delete($request, $other);

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertNotEmpty($this->flashes('success'));
    }

    public function testDeleteWithInvalidCsrfDoesNothing(): void
    {
        $self = $this->makeUser('me@example.com');
        $other = $this->makeUser('other@example.com');
        $this->setUser($self);

        $this->csrfTokenManager->shouldReceive('isTokenValid')->andReturn(false);
        $this->entityManager->shouldNotReceive('remove');
        $this->entityManager->shouldNotReceive('flush');
        $this->expectRoute('app_user_index', '/users');

        $request = Request::create('/users/'.$other->getId(), 'DELETE', ['_token' => 'bad']);

        $response = $this->controller->delete($request, $other);

        $this->assertInstanceOf(RedirectResponse::class, $response);
    }

    public function testResendInvitationRefusesIfAlreadyVerified(): void
    {
        $user = $this->makeUser('verified@example.com');
        $user->setIsVerified(true);
        $this->expectRoute('app_user_index', '/users');

        $request = Request::create('/users/'.$user->getId().'/resend-invitation', 'POST', ['_token' => 'good']);

        $this->invitationService->shouldNotReceive('sendInvitation');

        $response = $this->controller->resendInvitation($request, $user);

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertNotEmpty($this->flashes('warning'));
    }

    public function testResendInvitationSendsWhenNotVerifiedWithValidCsrf(): void
    {
        $user = $this->makeUser('unverified@example.com');
        $this->csrfTokenManager->shouldReceive('isTokenValid')
            ->with(m::on(fn (CsrfToken $t) => $t->getId() === 'resend-invitation-'.$user->getId()))
            ->andReturn(true);
        $this->invitationService->shouldReceive('sendInvitation')->with($user)->once();
        $this->expectRoute('app_user_index', '/users');

        $request = Request::create('/users/'.$user->getId().'/resend-invitation', 'POST', ['_token' => 'good']);

        $response = $this->controller->resendInvitation($request, $user);

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertNotEmpty($this->flashes('success'));
    }

    public function testResendInvitationReportsErrorOnSendFailure(): void
    {
        $user = $this->makeUser('unverified@example.com');
        $this->csrfTokenManager->shouldReceive('isTokenValid')->andReturn(true);
        $this->invitationService->shouldReceive('sendInvitation')->andThrow(new \RuntimeException('boom'));
        $this->expectRoute('app_user_index', '/users');

        $request = Request::create('/users/'.$user->getId().'/resend-invitation', 'POST', ['_token' => 'good']);

        $response = $this->controller->resendInvitation($request, $user);

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $errors = $this->flashes('danger');
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('boom', $errors[0]);
    }

    private function makeUser(string $email): User
    {
        $user = new User();
        $user->setEmail($email);
        $user->setFirstName('First');
        $user->setLastName('Last');

        return $user;
    }

    /**
     * @return FormInterface&m\LegacyMockInterface
     */
    private function mockForm(
        string $type,
        bool $submitted,
        bool $valid = false,
        ?\Closure $populateData = null,
    ): FormInterface {
        $form = m::mock(FormInterface::class);
        $form->shouldReceive('handleRequest');
        $form->shouldReceive('isSubmitted')->andReturn($submitted);
        if ($submitted) {
            $form->shouldReceive('isValid')->andReturn($valid);
        }
        $form->shouldReceive('createView')->andReturn(new FormView());

        $this->formFactory->shouldReceive('create')
            ->with($type, m::any(), m::any())
            ->andReturnUsing(function (string $type, mixed $data, array $opts) use ($form, $populateData) {
                if ($populateData !== null && $data !== null) {
                    $populateData($data);
                }

                return $form;
            });

        return $form;
    }
}
