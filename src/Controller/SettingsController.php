<?php

namespace App\Controller;

use App\Client\Google\GoogleClientFactory;
use App\Client\Google\InvalidGoogleTokenException;
use App\Entity\Organization;
use App\Form\OrganizationType;
use App\Repository\OrganizationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/settings')]
#[IsGranted('ROLE_ADMIN')]
class SettingsController extends AbstractController
{
    public function __construct(
        private readonly OrganizationRepository $organizationRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly GoogleClientFactory $googleClientFactory,
    ) {
    }

    #[Route('', name: 'app_settings', methods: ['GET', 'POST'])]
    public function index(Request $request): Response
    {
        $organization = $this->organizationRepository->findOne();
        $isEdit = $organization !== null;

        if ($organization === null) {
            $organization = new Organization();
        }

        // Store original encrypted values so we can detect "leave blank to keep current"
        $originalSecret = $isEdit
            ? $organization->getPlanningCenterAppSecret()
            : null;
        $originalCredentials = $isEdit
            ? $organization->getGoogleOAuthCredentials()
            : null;

        $form = $this->createForm(OrganizationType::class, $organization, [
            'is_edit' => $isEdit,
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // If the user left the secret blank during edit, restore the original value
            if (
                $isEdit
                && ($organization->getPlanningCenterAppSecret() === ''
                    || $organization->getPlanningCenterAppSecret() === null)
            ) {
                $organization->setPlanningCenterAppSecret($originalSecret);
            }

            // If the user left the credentials blank during edit, restore the original value
            if (
                $isEdit
                && ($organization->getGoogleOAuthCredentials() === ''
                    || $organization->getGoogleOAuthCredentials() === null)
            ) {
                $organization->setGoogleOAuthCredentials($originalCredentials);
            }

            if (!$isEdit) {
                $this->entityManager->persist($organization);
            }

            $this->entityManager->flush();

            $this->addFlash(
                'success',
                'Organization settings have been saved.',
            );

            return $this->redirectToRoute('app_settings');
        }

        // Determine Google connection status
        $googleConnected = false;
        $googleError = null;

        if ($isEdit && $organization->getGoogleToken() !== null) {
            try {
                $googleClient = $this->googleClientFactory->create(
                    $organization,
                );
                $googleClient->initialize();
                $googleConnected = true;
            } catch (InvalidGoogleTokenException) {
                $googleError =
                    'Google token is invalid or expired. Please reconnect.';
            } catch (\Throwable $e) {
                $googleError =
                    'Unable to verify Google connection: '.$e->getMessage();
            }
        }

        return $this->render('settings/index.html.twig', [
            'form' => $form,
            'organization' => $isEdit ? $organization : null,
            'google_connected' => $googleConnected,
            'google_error' => $googleError,
            'is_edit' => $isEdit,
        ]);
    }

    #[Route(
        '/google/connect',
        name: 'app_settings_google_connect',
        methods: ['GET'],
    ),]
    public function googleConnect(): Response
    {
        $organization = $this->organizationRepository->findOne();

        if ($organization === null) {
            $this->addFlash(
                'warning',
                'Please configure your organization settings first.',
            );

            return $this->redirectToRoute('app_settings');
        }

        try {
            $googleClient = $this->buildOAuthGoogleClient($organization);
            $authUrl = $googleClient->createAuthUrl();

            return $this->redirect($authUrl);
        } catch (\Throwable $e) {
            $this->addFlash(
                'danger',
                'Failed to initiate Google OAuth: '.$e->getMessage(),
            );

            return $this->redirectToRoute('app_settings');
        }
    }

    #[Route(
        '/google/callback',
        name: 'app_settings_google_callback',
        methods: ['GET'],
    ),]
    public function googleCallback(Request $request): Response
    {
        $organization = $this->organizationRepository->findOne();

        if ($organization === null) {
            $this->addFlash(
                'warning',
                'Please configure your organization settings first.',
            );

            return $this->redirectToRoute('app_settings');
        }

        $code = $request->query->get('code');
        $error = $request->query->get('error');

        if ($error !== null) {
            $this->addFlash(
                'danger',
                sprintf('Google OAuth was denied: %s', $error),
            );

            return $this->redirectToRoute('app_settings');
        }

        if ($code === null || $code === '') {
            $this->addFlash(
                'danger',
                'No authorization code received from Google.',
            );

            return $this->redirectToRoute('app_settings');
        }

        try {
            $googleClient = $this->buildOAuthGoogleClient($organization);
            $googleClient->setAuthCode($code);

            // Persist the token to the organization
            $tokenData = $googleClient->getTokenData();

            if ($tokenData !== null) {
                $organization->setGoogleToken(
                    json_encode($tokenData, JSON_THROW_ON_ERROR),
                );
                $this->entityManager->flush();

                $this->addFlash(
                    'success',
                    'Google account connected successfully.',
                );
            } else {
                $this->addFlash(
                    'danger',
                    'Failed to obtain a token from Google.',
                );
            }
        } catch (InvalidGoogleTokenException) {
            $this->addFlash(
                'danger',
                'Google returned an invalid token. Please try again.',
            );
        } catch (\Throwable $e) {
            $this->addFlash(
                'danger',
                'Failed to complete Google OAuth: '.$e->getMessage(),
            );
        }

        return $this->redirectToRoute('app_settings');
    }

    /**
     * Builds a GoogleClient from the organization's OAuth credentials,
     * converting "installed" credentials to "web" format and overriding
     * the redirect URI with the callback URL for this application.
     */
    private function buildOAuthGoogleClient(
        Organization $organization,
    ): \App\Client\Google\GoogleClient {
        $credentials = json_decode(
            $organization->getGoogleOAuthCredentials(),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $callbackUrl = $this->generateUrl(
            'app_settings_google_callback',
            [],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );

        if (isset($credentials['web'])) {
            $credentials['web']['redirect_uris'] = [$callbackUrl];
        } elseif (isset($credentials['installed'])) {
            $credentials['web'] = $credentials['installed'];
            $credentials['web']['redirect_uris'] = [$callbackUrl];
            unset($credentials['installed']);
        }

        $tempOrg = clone $organization;
        $tempOrg->setGoogleOAuthCredentials(
            json_encode($credentials, JSON_THROW_ON_ERROR),
        );

        return $this->googleClientFactory->create($tempOrg);
    }

    #[Route(
        '/google/disconnect',
        name: 'app_settings_google_disconnect',
        methods: ['POST'],
    ),]
    public function googleDisconnect(Request $request): Response
    {
        $organization = $this->organizationRepository->findOne();

        if ($organization === null) {
            return $this->redirectToRoute('app_settings');
        }

        $token = $request->request->get('_token');

        if ($this->isCsrfTokenValid('google-disconnect', $token)) {
            $organization->setGoogleToken(null);
            $this->entityManager->flush();

            $this->addFlash('success', 'Google account has been disconnected.');
        }

        return $this->redirectToRoute('app_settings');
    }
}
