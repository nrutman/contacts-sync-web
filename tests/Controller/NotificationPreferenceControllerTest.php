<?php

namespace App\Tests\Controller;

use App\Controller\NotificationPreferenceController;
use App\Entity\User;
use App\Form\NotificationPreferenceType;
use Doctrine\ORM\EntityManagerInterface;
use Mockery as m;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;

class NotificationPreferenceControllerTest extends AbstractControllerTestCase
{
    private EntityManagerInterface|m\LegacyMockInterface $entityManager;

    private NotificationPreferenceController $controller;

    protected function setUp(): void
    {
        $this->entityManager = m::mock(EntityManagerInterface::class);

        $this->controller = new NotificationPreferenceController($this->entityManager);
        $this->controller->setContainer($this->buildBaseContainer());
    }

    public function testEditRendersFormWhenNotSubmitted(): void
    {
        $user = $this->makeUser();
        $this->setUser($user);
        $this->mockForm(NotificationPreferenceType::class, false);

        $this->expectRender('notification_preference/edit.html.twig');

        $response = $this->controller->edit(new Request());

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testEditFlushesAndRedirectsOnValidSubmit(): void
    {
        $user = $this->makeUser();
        $this->setUser($user);
        $this->mockForm(NotificationPreferenceType::class, true, true);
        $this->entityManager->shouldReceive('flush')->once();
        $this->expectRoute('app_notification_preferences', '/account/notifications');

        $response = $this->controller->edit(new Request());

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertSame('/account/notifications', $response->getTargetUrl());
        $this->assertNotEmpty($this->flashes('success'));
    }

    public function testEditDoesNotFlushIfFormInvalid(): void
    {
        $user = $this->makeUser();
        $this->setUser($user);
        $this->mockForm(NotificationPreferenceType::class, true, false);
        $this->entityManager->shouldNotReceive('flush');
        $this->expectRender('notification_preference/edit.html.twig');

        $response = $this->controller->edit(new Request());

        // Symfony renders invalid forms as 422
        $this->assertNotInstanceOf(RedirectResponse::class, $response);
    }

    private function makeUser(): User
    {
        $u = new User();
        $u->setEmail('me@example.com');
        $u->setFirstName('A');
        $u->setLastName('B');

        return $u;
    }

    /**
     * @return FormInterface&m\LegacyMockInterface
     */
    private function mockForm(string $type, bool $submitted, bool $valid = false): FormInterface
    {
        $form = m::mock(FormInterface::class);
        $form->shouldReceive('handleRequest');
        $form->shouldReceive('isSubmitted')->andReturn($submitted);
        if ($submitted) {
            $form->shouldReceive('isValid')->andReturn($valid);
        }
        $form->shouldReceive('createView')->andReturn(new FormView());

        $this->formFactory->shouldReceive('create')->with($type, m::any(), m::any())->andReturn($form);

        return $form;
    }
}
