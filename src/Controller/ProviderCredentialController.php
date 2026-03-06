<?php

namespace App\Controller;

use App\Client\Provider\OAuthProviderInterface;
use App\Client\Provider\ProviderRegistry;
use App\Entity\ProviderCredential;
use App\Form\ProviderCredentialType;
use App\Repository\OrganizationRepository;
use App\Repository\ProviderCredentialRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/credentials')]
#[IsGranted('ROLE_ADMIN')]
class ProviderCredentialController extends AbstractController
{
    public function __construct(
        private readonly OrganizationRepository $organizationRepository,
        private readonly ProviderCredentialRepository $credentialRepository,
        private readonly ProviderRegistry $providerRegistry,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route('', name: 'app_credentials_index', methods: ['GET'])]
    public function index(): Response
    {
        $organization = $this->organizationRepository->findOne();

        if ($organization === null) {
            $this->addFlash('warning', 'Please configure your organization in Settings first.');

            return $this->redirectToRoute('app_settings');
        }

        $credentials = $this->credentialRepository->findByOrganization($organization);

        $oauthProviders = [];
        foreach ($this->providerRegistry->all() as $name => $provider) {
            if ($provider instanceof OAuthProviderInterface) {
                $oauthProviders[] = $name;
            }
        }

        return $this->render('provider_credential/index.html.twig', [
            'credentials' => $credentials,
            'providers' => $this->providerRegistry->all(),
            'oauth_providers' => $oauthProviders,
        ]);
    }

    #[Route('/new/{providerName}', name: 'app_credentials_new', methods: ['GET', 'POST'])]
    public function new(Request $request, string $providerName): Response
    {
        $organization = $this->organizationRepository->findOne();

        if ($organization === null) {
            $this->addFlash('warning', 'Please configure your organization in Settings first.');

            return $this->redirectToRoute('app_settings');
        }

        $provider = $this->providerRegistry->get($providerName);

        $credential = new ProviderCredential();
        $credential->setProviderName($providerName);

        $form = $this->createForm(ProviderCredentialType::class, $credential, [
            'provider_name' => $providerName,
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $credential->setOrganization($organization);

            // Build credentials JSON from form fields
            $creds = $this->extractCredentialsFromForm($form, $provider);
            $credential->setCredentialsArray($creds);

            $this->entityManager->persist($credential);
            $this->entityManager->flush();

            $this->addFlash('success', sprintf(
                '%s credential "%s" has been created.',
                $provider->getDisplayName(),
                $credential->getDisplayLabel(),
            ));

            // If this is an OAuth provider, redirect to connect
            if ($provider instanceof OAuthProviderInterface) {
                return $this->redirectToRoute('app_credentials_oauth_connect', [
                    'id' => $credential->getId(),
                ]);
            }

            return $this->redirectToRoute('app_credentials_index');
        }

        return $this->render('provider_credential/new.html.twig', [
            'form' => $form,
            'provider' => $provider,
        ]);
    }

    #[Route('/{id}/edit', name: 'app_credentials_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, ProviderCredential $credential): Response
    {
        $provider = $this->providerRegistry->get($credential->getProviderName());

        $form = $this->createForm(ProviderCredentialType::class, $credential, [
            'is_edit' => true,
            'provider_name' => $credential->getProviderName(),
        ]);

        // Pre-fill non-sensitive credential fields
        $this->prefillFormFromCredentials($form, $credential, $provider);

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $creds = $this->extractCredentialsFromForm($form, $provider, $credential->getCredentialsArray());
            $credential->setCredentialsArray($creds);

            $this->entityManager->flush();

            $this->addFlash('success', sprintf(
                '%s credential "%s" has been updated.',
                $provider->getDisplayName(),
                $credential->getDisplayLabel(),
            ));

            return $this->redirectToRoute('app_credentials_index');
        }

        return $this->render('provider_credential/edit.html.twig', [
            'form' => $form,
            'credential' => $credential,
            'provider' => $provider,
        ]);
    }

    #[Route('/{id}', name: 'app_credentials_delete', methods: ['DELETE'])]
    public function delete(Request $request, ProviderCredential $credential): Response
    {
        $token = $request->request->get('_token');

        if ($this->isCsrfTokenValid('delete-credential-'.$credential->getId(), $token)) {
            $label = $credential->getDisplayLabel();
            $this->entityManager->remove($credential);
            $this->entityManager->flush();

            $this->addFlash('success', sprintf('Credential "%s" has been deleted.', $label));
        }

        return $this->redirectToRoute('app_credentials_index');
    }

    #[Route('/{id}/oauth/connect', name: 'app_credentials_oauth_connect', methods: ['GET'])]
    public function oauthConnect(ProviderCredential $credential): Response
    {
        $provider = $this->providerRegistry->get($credential->getProviderName());

        if (!$provider instanceof OAuthProviderInterface) {
            $this->addFlash('warning', 'This provider does not require OAuth.');

            return $this->redirectToRoute('app_credentials_index');
        }

        $callbackUrl = $this->generateUrl(
            'app_credentials_oauth_callback',
            ['id' => $credential->getId()],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );

        try {
            $authUrl = $provider->getOAuthStartUrl($credential, $callbackUrl);

            return $this->redirect($authUrl);
        } catch (\Throwable $e) {
            $this->addFlash('danger', 'Failed to initiate OAuth: '.$e->getMessage());

            return $this->redirectToRoute('app_credentials_index');
        }
    }

    #[Route('/{id}/oauth/callback', name: 'app_credentials_oauth_callback', methods: ['GET'])]
    public function oauthCallback(Request $request, ProviderCredential $credential): Response
    {
        $provider = $this->providerRegistry->get($credential->getProviderName());

        if (!$provider instanceof OAuthProviderInterface) {
            return $this->redirectToRoute('app_credentials_index');
        }

        $code = $request->query->get('code');
        $error = $request->query->get('error');

        if ($error !== null) {
            $this->addFlash('danger', sprintf('OAuth was denied: %s', $error));

            return $this->redirectToRoute('app_credentials_index');
        }

        if ($code === null || $code === '') {
            $this->addFlash('danger', 'No authorization code received.');

            return $this->redirectToRoute('app_credentials_index');
        }

        try {
            $callbackUrl = $this->generateUrl(
                'app_credentials_oauth_callback',
                ['id' => $credential->getId()],
                UrlGeneratorInterface::ABSOLUTE_URL,
            );

            $updatedCreds = $provider->handleOAuthCallback($credential, $code, $callbackUrl);
            $credential->setCredentialsArray($updatedCreds);
            $this->entityManager->flush();

            $this->addFlash('success', sprintf(
                '%s account connected successfully.',
                $this->providerRegistry->get($credential->getProviderName())->getDisplayName(),
            ));
        } catch (\Throwable $e) {
            $this->addFlash('danger', 'Failed to complete OAuth: '.$e->getMessage());
        }

        return $this->redirectToRoute('app_credentials_index');
    }

    #[Route('/{id}/oauth/disconnect', name: 'app_credentials_oauth_disconnect', methods: ['POST'])]
    public function oauthDisconnect(Request $request, ProviderCredential $credential): Response
    {
        $token = $request->request->get('_token');

        if ($this->isCsrfTokenValid('oauth-disconnect-'.$credential->getId(), $token)) {
            $creds = $credential->getCredentialsArray();
            unset($creds['token']);
            $credential->setCredentialsArray($creds);
            $this->entityManager->flush();

            $this->addFlash('success', 'OAuth token has been removed.');
        }

        return $this->redirectToRoute('app_credentials_index');
    }

    /**
     * Extracts credential field values from the form into an array.
     *
     * @param array<string, mixed> $existingCreds Existing credentials to merge with (for edit mode)
     *
     * @return array<string, mixed>
     */
    private function extractCredentialsFromForm(
        \Symfony\Component\Form\FormInterface $form,
        \App\Client\Provider\ProviderInterface $provider,
        array $existingCreds = [],
    ): array {
        $creds = $existingCreds;

        foreach ($provider->getCredentialFields() as $field) {
            $fieldName = 'credential_'.$field->name;

            if (!$form->has($fieldName)) {
                continue;
            }

            $value = $form->get($fieldName)->getData();

            // For edit mode: if sensitive field is blank, keep existing value
            if ($field->sensitive && ($value === '' || $value === null) && isset($existingCreds[$field->name])) {
                continue;
            }

            $creds[$field->name] = $value;
        }

        return $creds;
    }

    private function prefillFormFromCredentials(
        \Symfony\Component\Form\FormInterface $form,
        ProviderCredential $credential,
        \App\Client\Provider\ProviderInterface $provider,
    ): void {
        $creds = $credential->getCredentialsArray();

        foreach ($provider->getCredentialFields() as $field) {
            $fieldName = 'credential_'.$field->name;

            if (!$form->has($fieldName) || $field->sensitive) {
                continue;
            }

            $form->get($fieldName)->setData($creds[$field->name] ?? null);
        }
    }
}
