<?php

namespace App\Tests\Controller;

use App\Client\Provider\CredentialFieldDefinition;
use App\Client\Provider\OAuthProviderInterface;
use App\Client\Provider\ProviderInterface;
use App\Client\Provider\ProviderRegistry;
use App\Controller\ProviderCredentialController;
use App\Entity\Organization;
use App\Entity\ProviderCredential;
use App\Repository\OrganizationRepository;
use App\Repository\ProviderCredentialRepository;
use Doctrine\ORM\EntityManagerInterface;
use Mockery as m;
use Mockery\Adapter\Phpunit\MockeryTestCase;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Twig\Environment;

class ProviderCredentialControllerTest extends MockeryTestCase
{
    private OrganizationRepository|m\LegacyMockInterface $organizationRepository;
    private ProviderCredentialRepository|m\LegacyMockInterface $credentialRepository;
    private ProviderRegistry|m\LegacyMockInterface $providerRegistry;
    private EntityManagerInterface|m\LegacyMockInterface $entityManager;
    private RouterInterface|m\LegacyMockInterface $router;
    private FormFactoryInterface|m\LegacyMockInterface $formFactory;
    private Environment|m\LegacyMockInterface $twig;
    private CsrfTokenManagerInterface|m\LegacyMockInterface $csrfTokenManager;
    private Session $session;
    private RequestStack $requestStack;

    private ProviderCredentialController $controller;

    protected function setUp(): void
    {
        $this->organizationRepository = m::mock(OrganizationRepository::class);
        $this->credentialRepository = m::mock(ProviderCredentialRepository::class);
        $this->providerRegistry = m::mock(ProviderRegistry::class);
        $this->entityManager = m::mock(EntityManagerInterface::class);
        $this->router = m::mock(RouterInterface::class);
        $this->formFactory = m::mock(FormFactoryInterface::class);
        $this->twig = m::mock(Environment::class);
        $this->csrfTokenManager = m::mock(CsrfTokenManagerInterface::class);

        $this->session = new Session(new MockArraySessionStorage());
        $this->requestStack = new RequestStack();
        $request = new Request();
        $request->setSession($this->session);
        $this->requestStack->push($request);

        // Provide reasonable default route generation for any redirectToRoute call.
        $this->router
            ->shouldReceive('generate')
            ->andReturnUsing(static function (string $route, array $params = []) {
                $query = $params === [] ? '' : '?'.http_build_query($params);

                return '/'.$route.$query;
            })
            ->byDefault();

        $this->controller = new ProviderCredentialController(
            $this->organizationRepository,
            $this->credentialRepository,
            $this->providerRegistry,
            $this->entityManager,
        );

        $container = new Container();
        $container->set('router', $this->router);
        $container->set('request_stack', $this->requestStack);
        $container->set('form.factory', $this->formFactory);
        $container->set('twig', $this->twig);
        $container->set('security.csrf.token_manager', $this->csrfTokenManager);
        $this->controller->setContainer($container);
    }

    // ---------------------------------------------------------------------
    // index()
    // ---------------------------------------------------------------------

    public function testIndexRedirectsToSettingsWhenNoOrganization(): void
    {
        $this->organizationRepository->shouldReceive('findOne')->andReturn(null);

        $response = $this->controller->index();

        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertStringContainsString('app_settings', $response->getTargetUrl());
        self::assertSame(['warning'], array_keys($this->session->getFlashBag()->all()));
    }

    public function testIndexRendersCredentialsAndOauthProviderList(): void
    {
        $org = $this->makeOrganization();
        $cred = $this->makeCredential('mailchimp', $org);

        $oauthProvider = m::mock(ProviderInterface::class, OAuthProviderInterface::class);
        $apiKeyProvider = m::mock(ProviderInterface::class);

        $this->organizationRepository->shouldReceive('findOne')->andReturn($org);
        $this->credentialRepository->shouldReceive('findByOrganization')
            ->with($org)
            ->andReturn([$cred]);
        $this->providerRegistry->shouldReceive('all')->andReturn([
            'planning_center' => $oauthProvider,
            'mailchimp' => $apiKeyProvider,
        ]);

        $this->twig
            ->shouldReceive('render')
            ->once()
            ->with(
                'provider_credential/index.html.twig',
                m::on(function (array $ctx) use ($cred) {
                    return $ctx['credentials'] === [$cred]
                        && $ctx['oauth_providers'] === ['planning_center']
                        && array_key_exists('providers', $ctx);
                }),
            )
            ->andReturn('<html></html>');

        $response = $this->controller->index();

        self::assertSame(200, $response->getStatusCode());
    }

    // ---------------------------------------------------------------------
    // delete() — CSRF behaviour
    // ---------------------------------------------------------------------

    public function testDeleteRemovesCredentialWhenCsrfValid(): void
    {
        $cred = $this->makeCredential('mailchimp');

        $this->csrfTokenManager
            ->shouldReceive('isTokenValid')
            ->once()
            ->with(m::on(fn (CsrfToken $t) => $t->getId() === 'delete-credential-'.$cred->getId() && $t->getValue() === 'good-token'))
            ->andReturn(true);

        $this->entityManager->shouldReceive('remove')->once()->with($cred);
        $this->entityManager->shouldReceive('flush')->once();

        $request = new Request(request: ['_token' => 'good-token']);

        $response = $this->controller->delete($request, $cred);

        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertStringContainsString('app_credentials_index', $response->getTargetUrl());
        self::assertArrayHasKey('success', $this->session->getFlashBag()->all());
    }

    public function testDeleteRejectsInvalidCsrfTokenWithoutRemoving(): void
    {
        $cred = $this->makeCredential('mailchimp');

        $this->csrfTokenManager
            ->shouldReceive('isTokenValid')
            ->once()
            ->andReturn(false);

        $this->entityManager->shouldNotReceive('remove');
        $this->entityManager->shouldNotReceive('flush');

        $request = new Request(request: ['_token' => 'bad-token']);

        $response = $this->controller->delete($request, $cred);

        self::assertInstanceOf(RedirectResponse::class, $response);
        // No success flash should have been added.
        self::assertArrayNotHasKey('success', $this->session->getFlashBag()->all());
    }

    // ---------------------------------------------------------------------
    // oauthConnect()
    // ---------------------------------------------------------------------

    public function testOauthConnectRejectsNonOAuthProvider(): void
    {
        $cred = $this->makeCredential('mailchimp');
        $provider = m::mock(ProviderInterface::class);

        $this->providerRegistry->shouldReceive('get')->with('mailchimp')->andReturn($provider);

        $response = $this->controller->oauthConnect($cred);

        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertStringContainsString('app_credentials_index', $response->getTargetUrl());
        self::assertArrayHasKey('warning', $this->session->getFlashBag()->all());
    }

    public function testOauthConnectRedirectsToProviderAuthUrl(): void
    {
        $cred = $this->makeCredential('planning_center');
        $provider = m::mock(ProviderInterface::class, OAuthProviderInterface::class);

        $this->providerRegistry->shouldReceive('get')->with('planning_center')->andReturn($provider);
        $provider
            ->shouldReceive('getOAuthStartUrl')
            ->once()
            ->with($cred, m::on(fn (string $url) => str_contains($url, 'app_credentials_oauth_callback')))
            ->andReturn('https://provider.example/authorize?x=1');

        $response = $this->controller->oauthConnect($cred);

        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame('https://provider.example/authorize?x=1', $response->getTargetUrl());
    }

    public function testOauthConnectFlashesDangerOnException(): void
    {
        $cred = $this->makeCredential('planning_center');
        $provider = m::mock(ProviderInterface::class, OAuthProviderInterface::class);

        $this->providerRegistry->shouldReceive('get')->with('planning_center')->andReturn($provider);
        $provider
            ->shouldReceive('getOAuthStartUrl')
            ->andThrow(new \RuntimeException('config missing'));

        $response = $this->controller->oauthConnect($cred);

        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertStringContainsString('app_credentials_index', $response->getTargetUrl());
        $flashes = $this->session->getFlashBag()->all();
        self::assertArrayHasKey('danger', $flashes);
        self::assertStringContainsString('config missing', $flashes['danger'][0]);
    }

    // ---------------------------------------------------------------------
    // oauthCallback()
    // ---------------------------------------------------------------------

    public function testOauthCallbackIgnoresNonOauthProviderSilently(): void
    {
        $cred = $this->makeCredential('mailchimp');
        $provider = m::mock(ProviderInterface::class);

        $this->providerRegistry->shouldReceive('get')->with('mailchimp')->andReturn($provider);

        $response = $this->controller->oauthCallback(new Request(), $cred);

        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertStringContainsString('app_credentials_index', $response->getTargetUrl());
        // No flash, no DB writes, just a redirect.
        self::assertSame([], $this->session->getFlashBag()->all());
    }

    public function testOauthCallbackHandlesProviderError(): void
    {
        $cred = $this->makeCredential('planning_center');
        $provider = m::mock(ProviderInterface::class, OAuthProviderInterface::class);

        $this->providerRegistry->shouldReceive('get')->with('planning_center')->andReturn($provider);
        $provider->shouldNotReceive('handleOAuthCallback');

        $request = new Request(query: ['error' => 'access_denied']);

        $response = $this->controller->oauthCallback($request, $cred);

        self::assertInstanceOf(RedirectResponse::class, $response);
        $flashes = $this->session->getFlashBag()->all();
        self::assertArrayHasKey('danger', $flashes);
        self::assertStringContainsString('access_denied', $flashes['danger'][0]);
    }

    public function testOauthCallbackRejectsMissingCode(): void
    {
        $cred = $this->makeCredential('planning_center');
        $provider = m::mock(ProviderInterface::class, OAuthProviderInterface::class);

        $this->providerRegistry->shouldReceive('get')->with('planning_center')->andReturn($provider);
        $provider->shouldNotReceive('handleOAuthCallback');

        // No 'code' query param.
        $response = $this->controller->oauthCallback(new Request(), $cred);

        self::assertInstanceOf(RedirectResponse::class, $response);
        $flashes = $this->session->getFlashBag()->all();
        self::assertArrayHasKey('danger', $flashes);
    }

    public function testOauthCallbackRejectsEmptyCode(): void
    {
        $cred = $this->makeCredential('planning_center');
        $provider = m::mock(ProviderInterface::class, OAuthProviderInterface::class);

        $this->providerRegistry->shouldReceive('get')->with('planning_center')->andReturn($provider);
        $provider->shouldNotReceive('handleOAuthCallback');

        $request = new Request(query: ['code' => '']);

        $response = $this->controller->oauthCallback($request, $cred);

        self::assertInstanceOf(RedirectResponse::class, $response);
        $flashes = $this->session->getFlashBag()->all();
        self::assertArrayHasKey('danger', $flashes);
    }

    public function testOauthCallbackPersistsTokensOnSuccess(): void
    {
        $cred = $this->makeCredential('planning_center');
        $cred->setCredentialsArray(['client_id' => 'abc']);

        $provider = m::mock(ProviderInterface::class, OAuthProviderInterface::class);
        $provider->shouldReceive('getDisplayName')->andReturn('Planning Center');

        $this->providerRegistry->shouldReceive('get')->with('planning_center')->andReturn($provider);

        $provider
            ->shouldReceive('handleOAuthCallback')
            ->once()
            ->with($cred, 'auth-code-xyz', m::on(fn (string $u) => str_contains($u, 'app_credentials_oauth_callback')))
            ->andReturn(['client_id' => 'abc', 'token' => ['access_token' => 'tok-123']]);

        $this->entityManager->shouldReceive('flush')->once();

        $request = new Request(query: ['code' => 'auth-code-xyz']);

        $response = $this->controller->oauthCallback($request, $cred);

        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame(
            ['client_id' => 'abc', 'token' => ['access_token' => 'tok-123']],
            $cred->getCredentialsArray(),
        );
        self::assertArrayHasKey('success', $this->session->getFlashBag()->all());
    }

    public function testOauthCallbackFlashesDangerWhenProviderThrows(): void
    {
        $cred = $this->makeCredential('planning_center');

        $provider = m::mock(ProviderInterface::class, OAuthProviderInterface::class);
        $this->providerRegistry->shouldReceive('get')->with('planning_center')->andReturn($provider);

        $provider
            ->shouldReceive('handleOAuthCallback')
            ->andThrow(new \RuntimeException('token exchange failed'));

        $this->entityManager->shouldNotReceive('flush');

        $request = new Request(query: ['code' => 'abc']);

        $response = $this->controller->oauthCallback($request, $cred);

        self::assertInstanceOf(RedirectResponse::class, $response);
        $flashes = $this->session->getFlashBag()->all();
        self::assertArrayHasKey('danger', $flashes);
        self::assertStringContainsString('token exchange failed', $flashes['danger'][0]);
    }

    // ---------------------------------------------------------------------
    // oauthDisconnect()
    // ---------------------------------------------------------------------

    public function testOauthDisconnectRemovesTokenWhenCsrfValid(): void
    {
        $cred = $this->makeCredential('planning_center');
        $cred->setCredentialsArray([
            'client_id' => 'abc',
            'token' => ['access_token' => 'should-be-removed'],
        ]);

        $this->csrfTokenManager
            ->shouldReceive('isTokenValid')
            ->once()
            ->with(m::on(fn (CsrfToken $t) => $t->getId() === 'oauth-disconnect-'.$cred->getId() && $t->getValue() === 'ok'))
            ->andReturn(true);

        $this->entityManager->shouldReceive('flush')->once();

        $request = new Request(request: ['_token' => 'ok']);

        $response = $this->controller->oauthDisconnect($request, $cred);

        self::assertInstanceOf(RedirectResponse::class, $response);
        $remaining = $cred->getCredentialsArray();
        self::assertArrayNotHasKey('token', $remaining);
        self::assertSame(['client_id' => 'abc'], $remaining);
        self::assertArrayHasKey('success', $this->session->getFlashBag()->all());
    }

    public function testOauthDisconnectRejectsInvalidCsrfAndKeepsToken(): void
    {
        $cred = $this->makeCredential('planning_center');
        $cred->setCredentialsArray([
            'token' => ['access_token' => 'still-here'],
        ]);

        $this->csrfTokenManager->shouldReceive('isTokenValid')->andReturn(false);
        $this->entityManager->shouldNotReceive('flush');

        $request = new Request(request: ['_token' => 'bad']);

        $response = $this->controller->oauthDisconnect($request, $cred);

        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame(
            ['token' => ['access_token' => 'still-here']],
            $cred->getCredentialsArray(),
        );
        self::assertArrayNotHasKey('success', $this->session->getFlashBag()->all());
    }

    // ---------------------------------------------------------------------
    // edit() — sensitive field masking
    // ---------------------------------------------------------------------

    public function testEditPrefillsOnlyNonSensitiveFields(): void
    {
        $cred = $this->makeCredential('mailchimp');
        $cred->setCredentialsArray([
            'api_key' => 'secret-value',
            'datacenter' => 'us1',
        ]);

        $provider = m::mock(ProviderInterface::class);
        $provider->shouldReceive('getCredentialFields')->andReturn([
            new CredentialFieldDefinition('api_key', 'API Key', 'password', sensitive: true),
            new CredentialFieldDefinition('datacenter', 'Datacenter', 'text', sensitive: false),
        ]);

        $this->providerRegistry->shouldReceive('get')->with('mailchimp')->andReturn($provider);

        $form = m::mock(FormInterface::class);
        $apiKeyField = m::mock(FormInterface::class);
        $datacenterField = m::mock(FormInterface::class);

        $form->shouldReceive('handleRequest')->once();
        $form->shouldReceive('isSubmitted')->andReturn(false);
        // For prefill: both fields exist on the form.
        $form->shouldReceive('has')->with('credential_api_key')->andReturn(true);
        $form->shouldReceive('has')->with('credential_datacenter')->andReturn(true);
        // The non-sensitive field is set, the sensitive one must NOT be touched.
        $form->shouldReceive('get')->with('credential_datacenter')->andReturn($datacenterField);
        $datacenterField->shouldReceive('setData')->once()->with('us1');
        // Sensitive field's setData must never be called — leaks would happen.
        $form->shouldNotReceive('get')->with('credential_api_key');
        $apiKeyField->shouldNotReceive('setData');

        $form->shouldReceive('createView')->andReturn(new \Symfony\Component\Form\FormView());

        $this->formFactory
            ->shouldReceive('create')
            ->once()
            ->with(
                \App\Form\ProviderCredentialType::class,
                $cred,
                m::on(fn (array $opts) => $opts['is_edit'] === true && $opts['provider_name'] === 'mailchimp'),
            )
            ->andReturn($form);

        $this->twig
            ->shouldReceive('render')
            ->once()
            ->with('provider_credential/edit.html.twig', m::any())
            ->andReturn('<html></html>');

        $response = $this->controller->edit(new Request(), $cred);

        self::assertSame(200, $response->getStatusCode());
    }

    public function testEditPreservesExistingSensitiveValueWhenSubmittedBlank(): void
    {
        $cred = $this->makeCredential('mailchimp');
        $cred->setCredentialsArray([
            'api_key' => 'existing-secret',
            'datacenter' => 'us1',
        ]);

        $provider = m::mock(ProviderInterface::class);
        $provider->shouldReceive('getDisplayName')->andReturn('Mailchimp');
        $provider->shouldReceive('getCredentialFields')->andReturn([
            new CredentialFieldDefinition('api_key', 'API Key', 'password', sensitive: true),
            new CredentialFieldDefinition('datacenter', 'Datacenter', 'text', sensitive: false),
        ]);

        $this->providerRegistry->shouldReceive('get')->with('mailchimp')->andReturn($provider);

        $form = m::mock(FormInterface::class);
        $apiKeyField = m::mock(FormInterface::class);
        $datacenterField = m::mock(FormInterface::class);

        // Submitted + valid form, blank sensitive field.
        $form->shouldReceive('handleRequest')->once();
        $form->shouldReceive('isSubmitted')->andReturn(true);
        $form->shouldReceive('isValid')->andReturn(true);

        // Prefill non-sensitive datacenter.
        $form->shouldReceive('has')->with('credential_api_key')->andReturn(true);
        $form->shouldReceive('has')->with('credential_datacenter')->andReturn(true);
        $form->shouldReceive('get')->with('credential_datacenter')->andReturn($datacenterField);
        $datacenterField->shouldReceive('setData')->with('us1')->once();

        // Field reads during extractCredentialsFromForm.
        $form->shouldReceive('get')->with('credential_api_key')->andReturn($apiKeyField);
        // User left the api_key blank — extract returns ''.
        $apiKeyField->shouldReceive('getData')->andReturn('');
        $datacenterField->shouldReceive('getData')->andReturn('us2');

        $this->formFactory
            ->shouldReceive('create')
            ->andReturn($form);

        $this->entityManager->shouldReceive('flush')->once();

        $response = $this->controller->edit(new Request(), $cred);

        // Sensitive value preserved; non-sensitive value updated.
        $merged = $cred->getCredentialsArray();
        self::assertSame('existing-secret', $merged['api_key']);
        self::assertSame('us2', $merged['datacenter']);
        self::assertInstanceOf(RedirectResponse::class, $response);
    }

    public function testEditUpdatesSensitiveValueWhenProvided(): void
    {
        $cred = $this->makeCredential('mailchimp');
        $cred->setCredentialsArray([
            'api_key' => 'old-secret',
            'datacenter' => 'us1',
        ]);

        $provider = m::mock(ProviderInterface::class);
        $provider->shouldReceive('getDisplayName')->andReturn('Mailchimp');
        $provider->shouldReceive('getCredentialFields')->andReturn([
            new CredentialFieldDefinition('api_key', 'API Key', 'password', sensitive: true),
            new CredentialFieldDefinition('datacenter', 'Datacenter', 'text', sensitive: false),
        ]);

        $this->providerRegistry->shouldReceive('get')->with('mailchimp')->andReturn($provider);

        $form = m::mock(FormInterface::class);
        $apiKeyField = m::mock(FormInterface::class);
        $datacenterField = m::mock(FormInterface::class);

        $form->shouldReceive('handleRequest')->once();
        $form->shouldReceive('isSubmitted')->andReturn(true);
        $form->shouldReceive('isValid')->andReturn(true);

        // Prefill — only datacenter.
        $form->shouldReceive('has')->with('credential_api_key')->andReturn(true);
        $form->shouldReceive('has')->with('credential_datacenter')->andReturn(true);
        $form->shouldReceive('get')->with('credential_datacenter')->andReturn($datacenterField);
        $datacenterField->shouldReceive('setData')->with('us1');

        // User submitted a new sensitive value.
        $form->shouldReceive('get')->with('credential_api_key')->andReturn($apiKeyField);
        $apiKeyField->shouldReceive('getData')->andReturn('rotated-secret');
        $datacenterField->shouldReceive('getData')->andReturn('us1');

        $this->formFactory->shouldReceive('create')->andReturn($form);
        $this->entityManager->shouldReceive('flush')->once();

        $response = $this->controller->edit(new Request(), $cred);

        $merged = $cred->getCredentialsArray();
        self::assertSame('rotated-secret', $merged['api_key']);
        self::assertInstanceOf(RedirectResponse::class, $response);
    }

    // ---------------------------------------------------------------------
    // new() — error & happy paths
    // ---------------------------------------------------------------------

    public function testNewRedirectsToSettingsWhenNoOrganization(): void
    {
        $this->organizationRepository->shouldReceive('findOne')->andReturn(null);

        $response = $this->controller->new(new Request(), 'mailchimp');

        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertStringContainsString('app_settings', $response->getTargetUrl());
        self::assertArrayHasKey('warning', $this->session->getFlashBag()->all());
    }

    public function testNewRedirectsToOauthConnectAfterSavingOAuthCredential(): void
    {
        $org = $this->makeOrganization();
        $this->organizationRepository->shouldReceive('findOne')->andReturn($org);

        $provider = m::mock(ProviderInterface::class, OAuthProviderInterface::class);
        $provider->shouldReceive('getDisplayName')->andReturn('Planning Center');
        $provider->shouldReceive('getCredentialFields')->andReturn([
            new CredentialFieldDefinition('client_id', 'Client ID'),
        ]);

        $this->providerRegistry->shouldReceive('get')->with('planning_center')->andReturn($provider);

        $form = m::mock(FormInterface::class);
        $clientIdField = m::mock(FormInterface::class);
        $form->shouldReceive('handleRequest')->once();
        $form->shouldReceive('isSubmitted')->andReturn(true);
        $form->shouldReceive('isValid')->andReturn(true);
        $form->shouldReceive('has')->with('credential_client_id')->andReturn(true);
        $form->shouldReceive('get')->with('credential_client_id')->andReturn($clientIdField);
        $clientIdField->shouldReceive('getData')->andReturn('cid-123');

        $this->formFactory->shouldReceive('create')->andReturn($form);

        $this->entityManager->shouldReceive('persist')->once()->with(m::type(ProviderCredential::class));
        $this->entityManager->shouldReceive('flush')->once();

        $response = $this->controller->new(new Request(), 'planning_center');

        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertStringContainsString('app_credentials_oauth_connect', $response->getTargetUrl());
    }

    public function testNewRedirectsToIndexAfterSavingNonOAuthCredential(): void
    {
        $org = $this->makeOrganization();
        $this->organizationRepository->shouldReceive('findOne')->andReturn($org);

        $provider = m::mock(ProviderInterface::class);
        $provider->shouldReceive('getDisplayName')->andReturn('Mailchimp');
        $provider->shouldReceive('getCredentialFields')->andReturn([
            new CredentialFieldDefinition('api_key', 'API Key', 'password', sensitive: true),
        ]);

        $this->providerRegistry->shouldReceive('get')->with('mailchimp')->andReturn($provider);

        $form = m::mock(FormInterface::class);
        $apiKeyField = m::mock(FormInterface::class);
        $form->shouldReceive('handleRequest')->once();
        $form->shouldReceive('isSubmitted')->andReturn(true);
        $form->shouldReceive('isValid')->andReturn(true);
        $form->shouldReceive('has')->with('credential_api_key')->andReturn(true);
        $form->shouldReceive('get')->with('credential_api_key')->andReturn($apiKeyField);
        $apiKeyField->shouldReceive('getData')->andReturn('a-secret');

        $this->formFactory->shouldReceive('create')->andReturn($form);

        $this->entityManager->shouldReceive('persist')->once();
        $this->entityManager->shouldReceive('flush')->once();

        $response = $this->controller->new(new Request(), 'mailchimp');

        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertStringContainsString('app_credentials_index', $response->getTargetUrl());
    }

    public function testNewRendersFormWhenNotSubmitted(): void
    {
        $org = $this->makeOrganization();
        $this->organizationRepository->shouldReceive('findOne')->andReturn($org);

        $provider = m::mock(ProviderInterface::class);
        $provider->shouldReceive('getCredentialFields')->andReturn([]);
        $this->providerRegistry->shouldReceive('get')->with('mailchimp')->andReturn($provider);

        $form = m::mock(FormInterface::class);
        $form->shouldReceive('handleRequest')->once();
        $form->shouldReceive('isSubmitted')->andReturn(false);
        $form->shouldReceive('createView')->andReturn(new \Symfony\Component\Form\FormView());

        $this->formFactory->shouldReceive('create')->andReturn($form);

        $this->entityManager->shouldNotReceive('persist');
        $this->entityManager->shouldNotReceive('flush');

        $this->twig
            ->shouldReceive('render')
            ->with('provider_credential/new.html.twig', m::any())
            ->andReturn('<html></html>');

        $response = $this->controller->new(new Request(), 'mailchimp');

        self::assertSame(200, $response->getStatusCode());
    }

    // ---------------------------------------------------------------------
    // helpers
    // ---------------------------------------------------------------------

    private function makeOrganization(): Organization
    {
        $org = new Organization();
        $org->setName('Test Org');

        return $org;
    }

    private function makeCredential(string $providerName, ?Organization $org = null): ProviderCredential
    {
        $cred = new ProviderCredential();
        $cred->setOrganization($org ?? $this->makeOrganization());
        $cred->setProviderName($providerName);
        $cred->setLabel('Default');
        $cred->setCredentialsArray([]);

        return $cred;
    }
}
