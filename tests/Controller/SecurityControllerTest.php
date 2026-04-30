<?php

namespace App\Tests\Controller;

use App\Controller\SecurityController;
use App\Entity\User;
use Mockery as m;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\LimiterInterface;
use Symfony\Component\RateLimiter\RateLimit;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

class SecurityControllerTest extends AbstractControllerTestCase
{
    private RateLimiterFactoryInterface|m\LegacyMockInterface $loginLimiter;
    private LimiterInterface|m\LegacyMockInterface $limiter;
    private AuthenticationUtils|m\LegacyMockInterface $authenticationUtils;

    private SecurityController $controller;

    protected function setUp(): void
    {
        $this->loginLimiter = m::mock(RateLimiterFactoryInterface::class);
        $this->limiter = m::mock(LimiterInterface::class);
        $this->authenticationUtils = m::mock(AuthenticationUtils::class);

        $this->controller = new SecurityController($this->loginLimiter);
        $this->controller->setContainer($this->buildBaseContainer());
    }

    public function testLoginRedirectsToDashboardIfAlreadyAuthenticated(): void
    {
        $user = new User();
        $user->setEmail('user@example.com');
        $user->setFirstName('A');
        $user->setLastName('B');
        $this->setUser($user);

        $this->expectRoute('app_dashboard', '/');

        $response = $this->controller->login(new Request(), $this->authenticationUtils);

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertSame('/', $response->getTargetUrl());
    }

    public function testLoginGetRendersFormWithoutConsumingRateLimit(): void
    {
        $this->tokenStorage->shouldReceive('getToken')->andReturn(null);
        $this->loginLimiter->shouldReceive('create')->andReturn($this->limiter);
        $this->limiter->shouldNotReceive('consume');

        $this->authenticationUtils->shouldReceive('getLastAuthenticationError')->andReturn(null);
        $this->authenticationUtils->shouldReceive('getLastUsername')->andReturn('');

        $captured = [];
        $this->expectRender('security/login.html.twig', function (array $params) use (&$captured): bool {
            $captured = $params;

            return true;
        });

        $request = Request::create('/login', 'GET');

        $response = $this->controller->login($request, $this->authenticationUtils);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertFalse($captured['rate_limit_exceeded']);
    }

    public function testLoginPostConsumesRateLimitAndExposesError(): void
    {
        $this->tokenStorage->shouldReceive('getToken')->andReturn(null);
        $this->loginLimiter->shouldReceive('create')->andReturn($this->limiter);

        $rateLimit = m::mock(RateLimit::class);
        $rateLimit->shouldReceive('isAccepted')->andReturn(true);
        $this->limiter->shouldReceive('consume')->once()->andReturn($rateLimit);

        $error = new BadCredentialsException('Bad creds');
        $this->authenticationUtils->shouldReceive('getLastAuthenticationError')->andReturn($error);
        $this->authenticationUtils->shouldReceive('getLastUsername')->andReturn('foo@example.com');

        $captured = [];
        $this->expectRender('security/login.html.twig', function (array $params) use (&$captured): bool {
            $captured = $params;

            return true;
        });

        $request = Request::create('/login', 'POST');

        $this->controller->login($request, $this->authenticationUtils);

        $this->assertSame('foo@example.com', $captured['last_username']);
        $this->assertSame($error, $captured['error']);
        $this->assertFalse($captured['rate_limit_exceeded']);
    }

    public function testLoginPostMarksRateLimitExceededAndHidesError(): void
    {
        $this->tokenStorage->shouldReceive('getToken')->andReturn(null);
        $this->loginLimiter->shouldReceive('create')->andReturn($this->limiter);

        $rateLimit = m::mock(RateLimit::class);
        $rateLimit->shouldReceive('isAccepted')->andReturn(false);
        $this->limiter->shouldReceive('consume')->andReturn($rateLimit);

        $error = new BadCredentialsException('shouldnt show');
        $this->authenticationUtils->shouldReceive('getLastAuthenticationError')->andReturn($error);
        $this->authenticationUtils->shouldReceive('getLastUsername')->andReturn('');

        $captured = [];
        $this->expectRender('security/login.html.twig', function (array $params) use (&$captured): bool {
            $captured = $params;

            return true;
        });

        $request = Request::create('/login', 'POST');

        $this->controller->login($request, $this->authenticationUtils);

        $this->assertTrue($captured['rate_limit_exceeded']);
        $this->assertNull($captured['error']);
    }

    public function testLogoutThrows(): void
    {
        $this->expectException(\LogicException::class);

        $this->controller->logout();
    }
}
