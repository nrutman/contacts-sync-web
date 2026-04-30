<?php

namespace App\Tests\Controller;

use Mockery\Adapter\Phpunit\MockeryTestCase;
use Mockery as m;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Twig\Environment;

/**
 * Wires a typical controller-test container with common service mocks
 * (twig, request_stack, router, csrf manager, token storage, form.factory).
 */
abstract class AbstractControllerTestCase extends MockeryTestCase
{
    protected Container $container;
    protected Environment|m\LegacyMockInterface $twig;
    protected Session $session;
    protected RequestStack $requestStack;
    protected UrlGeneratorInterface|m\LegacyMockInterface $urlGenerator;
    protected CsrfTokenManagerInterface|m\LegacyMockInterface $csrfTokenManager;
    protected TokenStorageInterface|m\LegacyMockInterface $tokenStorage;
    protected FormFactoryInterface|m\LegacyMockInterface $formFactory;

    protected function buildBaseContainer(): Container
    {
        $this->twig = m::mock(Environment::class);
        $this->urlGenerator = m::mock(UrlGeneratorInterface::class);
        $this->csrfTokenManager = m::mock(CsrfTokenManagerInterface::class);
        $this->tokenStorage = m::mock(TokenStorageInterface::class);
        $this->formFactory = m::mock(FormFactoryInterface::class);

        $this->session = new Session(new MockArraySessionStorage());
        $this->requestStack = new RequestStack();
        $request = new Request();
        $request->setSession($this->session);
        $this->requestStack->push($request);

        $container = new Container();
        $container->set('twig', $this->twig);
        $container->set('request_stack', $this->requestStack);
        $container->set('router', $this->urlGenerator);
        $container->set('security.csrf.token_manager', $this->csrfTokenManager);
        $container->set('security.token_storage', $this->tokenStorage);
        $container->set('form.factory', $this->formFactory);

        $this->container = $container;

        return $container;
    }

    protected function setUser(UserInterface $user): void
    {
        $token = new UsernamePasswordToken($user, 'main', $user->getRoles());
        $this->tokenStorage->shouldReceive('getToken')->andReturn($token);
    }

    protected function expectRender(string $template, ?callable $paramAssertion = null, string $body = 'rendered'): void
    {
        $this->twig->shouldReceive('render')
            ->with($template, m::on(function (array $params) use ($paramAssertion): bool {
                if ($paramAssertion !== null) {
                    return (bool) $paramAssertion($params);
                }

                return true;
            }))
            ->andReturn($body);
    }

    protected function expectRoute(string $name, string $url): void
    {
        $this->urlGenerator->shouldReceive('generate')
            ->with($name, m::any(), m::any())
            ->andReturn($url);
    }

    protected function flashes(string $type): array
    {
        return $this->session->getFlashBag()->get($type);
    }
}
