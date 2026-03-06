<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

class SecurityController extends AbstractController
{
    public function __construct(
        private readonly RateLimiterFactory $loginLimiter,
    ) {
    }

    #[Route('/login', name: 'app_login')]
    public function login(
        Request $request,
        AuthenticationUtils $authenticationUtils,
    ): Response {
        if ($this->getUser()) {
            return $this->redirectToRoute('app_dashboard');
        }

        // Rate-limit login attempts by IP
        $limiter = $this->loginLimiter->create($request->getClientIp());

        $error = $authenticationUtils->getLastAuthenticationError();
        $lastUsername = $authenticationUtils->getLastUsername();

        // Consume a token on POST (actual login attempt)
        $rateLimitExceeded = false;

        if ($request->isMethod('POST')) {
            $limit = $limiter->consume();

            if (!$limit->isAccepted()) {
                $rateLimitExceeded = true;
            }
        }

        return $this->render('security/login.html.twig', [
            'last_username' => $lastUsername,
            'error' => $rateLimitExceeded ? null : $error,
            'rate_limit_exceeded' => $rateLimitExceeded,
        ]);
    }

    #[Route('/logout', name: 'app_logout')]
    public function logout(): never
    {
        // This method is intercepted by the logout key on the firewall.
        throw new \LogicException('This method should never be reached.');
    }
}
