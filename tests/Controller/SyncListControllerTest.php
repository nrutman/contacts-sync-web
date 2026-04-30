<?php

namespace App\Tests\Controller;

use App\Controller\SyncListController;
use App\Entity\Organization;
use App\Entity\SyncList;
use App\Entity\User;
use App\Repository\ManualContactRepository;
use App\Repository\OrganizationRepository;
use App\Repository\SyncListRepository;
use App\Repository\SyncRunContactRepository;
use App\Repository\SyncRunRepository;
use App\Sync\SyncResult;
use App\Sync\SyncService;
use Doctrine\ORM\EntityManagerInterface;
use Mockery\Adapter\Phpunit\MockeryTestCase;
use Mockery as m;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Flash\FlashBag;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\RateLimiter\LimiterInterface;
use Symfony\Component\RateLimiter\RateLimit;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Twig\Environment;

class SyncListControllerTest extends MockeryTestCase
{
    private OrganizationRepository|m\LegacyMockInterface $organizationRepository;
    private SyncListRepository|m\LegacyMockInterface $syncListRepository;
    private SyncRunRepository|m\LegacyMockInterface $syncRunRepository;
    private SyncRunContactRepository|m\LegacyMockInterface $syncRunContactRepository;
    private ManualContactRepository|m\LegacyMockInterface $manualContactRepository;
    private EntityManagerInterface|m\LegacyMockInterface $entityManager;
    private SyncService|m\LegacyMockInterface $syncService;
    private RateLimiterFactoryInterface|m\LegacyMockInterface $syncTriggerLimiter;

    private RouterInterface|m\LegacyMockInterface $router;
    private CsrfTokenManagerInterface|m\LegacyMockInterface $csrfTokenManager;
    private Environment|m\LegacyMockInterface $twig;
    private TokenStorageInterface|m\LegacyMockInterface $tokenStorage;
    private Session $session;

    private SyncListController $controller;
    private Organization $organization;

    protected function setUp(): void
    {
        $this->organizationRepository = m::mock(OrganizationRepository::class);
        $this->syncListRepository = m::mock(SyncListRepository::class);
        $this->syncRunRepository = m::mock(SyncRunRepository::class);
        $this->syncRunContactRepository = m::mock(SyncRunContactRepository::class);
        $this->manualContactRepository = m::mock(ManualContactRepository::class);
        $this->entityManager = m::mock(EntityManagerInterface::class);
        $this->syncService = m::mock(SyncService::class);
        $this->syncTriggerLimiter = m::mock(RateLimiterFactoryInterface::class);

        $this->router = m::mock(RouterInterface::class);
        $this->csrfTokenManager = m::mock(CsrfTokenManagerInterface::class);
        $this->twig = m::mock(Environment::class);
        $this->tokenStorage = m::mock(TokenStorageInterface::class);

        $this->organization = new Organization();
        $this->organization->setName('Test Org');

        $this->controller = new SyncListController(
            $this->organizationRepository,
            $this->syncListRepository,
            $this->syncRunRepository,
            $this->syncRunContactRepository,
            $this->manualContactRepository,
            $this->entityManager,
            $this->syncService,
            $this->syncTriggerLimiter,
        );

        // Build a session with a flash bag for addFlash().
        $this->session = new Session(new MockArraySessionStorage(), null, new FlashBag());
        $requestStack = new RequestStack();
        $request = new Request();
        $request->setSession($this->session);
        $requestStack->push($request);

        // Default router behaviour: generate predictable URLs for redirects.
        $this->router->shouldReceive('generate')
            ->andReturnUsing(fn (string $route, array $params = []) => '/'.$route);

        $container = new Container();
        $container->set('router', $this->router);
        $container->set('request_stack', $requestStack);
        $container->set('security.csrf.token_manager', $this->csrfTokenManager);
        $container->set('twig', $this->twig);
        $container->set('security.token_storage', $this->tokenStorage);
        $this->controller->setContainer($container);
    }

    // -------------------------------------------------------------------------
    // index()
    // -------------------------------------------------------------------------

    public function testIndexRedirectsToSettingsWhenNoOrganization(): void
    {
        $this->organizationRepository->shouldReceive('findOne')->andReturn(null);

        $response = $this->controller->index();

        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame('/app_settings', $response->getTargetUrl());
        self::assertContains(
            'Please configure your organization in Settings first.',
            $this->session->getFlashBag()->get('warning'),
        );
    }

    public function testIndexRendersListsScopedToOrganization(): void
    {
        $this->organizationRepository->shouldReceive('findOne')
            ->andReturn($this->organization);

        $list1 = $this->makeList('A');
        $list2 = $this->makeList('B');

        $this->syncListRepository->shouldReceive('findByOrganization')
            ->with($this->organization)
            ->andReturn([$list1, $list2]);
        $this->syncListRepository->shouldReceive('findEnabledByOrganization')
            ->with($this->organization)
            ->andReturn([$list1]);
        $this->syncRunRepository->shouldReceive('findSourceCountsByOrganization')
            ->with($this->organization)
            ->andReturn(['mailchimp' => 5]);

        $this->twig->shouldReceive('render')
            ->with('sync_list/index.html.twig', m::on(function (array $params) use ($list1, $list2) {
                return $params['sync_lists'] === [$list1, $list2]
                    && $params['enabledSyncLists'] === [$list1]
                    && $params['contactCounts'] === ['mailchimp' => 5];
            }))
            ->andReturn('<html>index</html>');

        $response = $this->controller->index();

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('<html>index</html>', $response->getContent());
    }

    // -------------------------------------------------------------------------
    // delete()
    // -------------------------------------------------------------------------

    public function testDeleteRemovesListWhenCsrfTokenIsValid(): void
    {
        $list = $this->makeList('To delete');

        $this->csrfTokenManager->shouldReceive('isTokenValid')
            ->with(m::on(fn (CsrfToken $t) => $t->getId() === 'delete-sync-list-'.$list->getId() && $t->getValue() === 'good'))
            ->andReturn(true);

        $this->entityManager->shouldReceive('remove')->once()->with($list);
        $this->entityManager->shouldReceive('flush')->once();

        $request = new Request(request: ['_token' => 'good']);

        $response = $this->controller->delete($request, $list);

        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame('/app_sync_list_index', $response->getTargetUrl());
        self::assertContains(
            'Sync list "To delete" has been deleted.',
            $this->session->getFlashBag()->get('success'),
        );
    }

    public function testDeleteSkipsRemovalWhenCsrfTokenIsInvalid(): void
    {
        $list = $this->makeList('Keep me');

        $this->csrfTokenManager->shouldReceive('isTokenValid')->andReturn(false);

        // Critical: no remove/flush should happen
        $this->entityManager->shouldNotReceive('remove');
        $this->entityManager->shouldNotReceive('flush');

        $request = new Request(request: ['_token' => 'bad']);

        $response = $this->controller->delete($request, $list);

        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame('/app_sync_list_index', $response->getTargetUrl());
        self::assertSame([], $this->session->getFlashBag()->get('success'));
    }

    // -------------------------------------------------------------------------
    // toggle()
    // -------------------------------------------------------------------------

    public function testToggleEnablesDisabledList(): void
    {
        $list = $this->makeList('Was off');
        $list->setIsEnabled(false);

        $this->csrfTokenManager->shouldReceive('isTokenValid')
            ->with(m::on(fn (CsrfToken $t) => $t->getId() === 'toggle-sync-list-'.$list->getId()))
            ->andReturn(true);
        $this->entityManager->shouldReceive('flush')->once();

        $request = new Request(request: ['_token' => 'good']);

        $response = $this->controller->toggle($request, $list);

        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertTrue($list->isEnabled());
        self::assertContains(
            'Sync list "Was off" has been enabled.',
            $this->session->getFlashBag()->get('success'),
        );
    }

    public function testToggleDisablesEnabledList(): void
    {
        $list = $this->makeList('Was on');
        $list->setIsEnabled(true);

        $this->csrfTokenManager->shouldReceive('isTokenValid')->andReturn(true);
        $this->entityManager->shouldReceive('flush')->once();

        $response = $this->controller->toggle(new Request(request: ['_token' => 'good']), $list);

        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertFalse($list->isEnabled());
        self::assertContains(
            'Sync list "Was on" has been disabled.',
            $this->session->getFlashBag()->get('success'),
        );
    }

    public function testToggleSkipsWhenCsrfInvalid(): void
    {
        $list = $this->makeList('Stay');
        $list->setIsEnabled(true);

        $this->csrfTokenManager->shouldReceive('isTokenValid')->andReturn(false);
        $this->entityManager->shouldNotReceive('flush');

        $this->controller->toggle(new Request(request: ['_token' => 'bad']), $list);

        self::assertTrue($list->isEnabled());
    }

    // -------------------------------------------------------------------------
    // history()
    // -------------------------------------------------------------------------

    public function testHistoryUsesPagination(): void
    {
        $list = $this->makeList('Hist');

        $this->syncRunRepository->shouldReceive('findBySyncList')
            ->with($list, 25, 50)
            ->andReturn(array_fill(0, 25, 'run'));

        $this->twig->shouldReceive('render')
            ->with('sync_list/history.html.twig', m::on(function (array $params) use ($list) {
                return $params['sync_list'] === $list
                    && $params['page'] === 3
                    && $params['has_more'] === true
                    && \count($params['sync_runs']) === 25;
            }))
            ->andReturn('<html>hist</html>');

        $request = new Request(query: ['page' => '3']);
        $response = $this->controller->history($request, $list);

        self::assertSame(200, $response->getStatusCode());
    }

    public function testHistoryClampsNegativePageToOne(): void
    {
        $list = $this->makeList('Hist');

        $this->syncRunRepository->shouldReceive('findBySyncList')
            ->with($list, 25, 0)
            ->andReturn([]);

        $this->twig->shouldReceive('render')
            ->with('sync_list/history.html.twig', m::on(fn (array $params) => $params['page'] === 1 && $params['has_more'] === false))
            ->andReturn('<html>hist</html>');

        $response = $this->controller->history(new Request(query: ['page' => '-5']), $list);

        self::assertSame(200, $response->getStatusCode());
    }

    // -------------------------------------------------------------------------
    // contacts()
    // -------------------------------------------------------------------------

    public function testContactsRendersSourceAndManualContacts(): void
    {
        $list = $this->makeList('CL');

        $this->syncRunContactRepository->shouldReceive('findByLatestSuccessfulRun')
            ->with($list)
            ->andReturn(['src1', 'src2']);
        $this->manualContactRepository->shouldReceive('findBySyncList')
            ->with($list)
            ->andReturn(['m1']);

        $this->twig->shouldReceive('render')
            ->with('sync_list/contacts.html.twig', m::on(fn (array $params) => $params['sync_list'] === $list
                && $params['source_contacts'] === ['src1', 'src2']
                && $params['manual_contacts'] === ['m1']))
            ->andReturn('<html>contacts</html>');

        $response = $this->controller->contacts($list);

        self::assertSame(200, $response->getStatusCode());
    }

    // -------------------------------------------------------------------------
    // runSync()
    // -------------------------------------------------------------------------

    public function testRunSyncRejectsInvalidCsrf(): void
    {
        $list = $this->makeList('R');

        $this->csrfTokenManager->shouldReceive('isTokenValid')->andReturn(false);

        // Critical: no rate-limiter consumption and no sync execution should occur.
        $this->syncTriggerLimiter->shouldNotReceive('create');
        $this->syncService->shouldNotReceive('executeSync');

        $response = $this->controller->runSync(
            new Request(request: ['_token' => 'bad']),
            $list,
        );

        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame('/app_sync_list_history', $response->getTargetUrl());
        self::assertContains('Invalid CSRF token.', $this->session->getFlashBag()->get('danger'));
    }

    public function testRunSyncReturnsRateLimitedFlashWhenLimitExceeded(): void
    {
        $list = $this->makeList('R');

        $this->csrfTokenManager->shouldReceive('isTokenValid')->andReturn(true);

        $rateLimit = m::mock(RateLimit::class);
        $rateLimit->shouldReceive('isAccepted')->andReturn(false);

        $limiter = m::mock(LimiterInterface::class);
        $limiter->shouldReceive('consume')->andReturn($rateLimit);

        $this->syncTriggerLimiter->shouldReceive('create')
            ->with((string) $list->getId())
            ->andReturn($limiter);

        // Sync should not be executed when rate-limited.
        $this->syncService->shouldNotReceive('executeSync');

        $response = $this->controller->runSync(
            new Request(request: ['_token' => 'good']),
            $list,
        );

        self::assertInstanceOf(RedirectResponse::class, $response);
        $flashes = $this->session->getFlashBag()->get('warning');
        self::assertNotEmpty($flashes);
        self::assertStringContainsString('Please wait', $flashes[0]);
    }

    public function testRunSyncBypassesRateLimiterForDryRun(): void
    {
        $list = $this->makeList('R');
        $user = new User();

        $this->csrfTokenManager->shouldReceive('isTokenValid')->andReturn(true);

        // Dry runs must not touch the rate limiter.
        $this->syncTriggerLimiter->shouldNotReceive('create');

        $token = m::mock(TokenInterface::class);
        $token->shouldReceive('getUser')->andReturn($user);
        $this->tokenStorage->shouldReceive('getToken')->andReturn($token);

        $this->syncService->shouldReceive('executeSync')
            ->withArgs(function (...$args) use ($list, $user) {
                return $args[0] === $list
                    && $args[1] === true   // dryRun
                    && $args[2] === $user
                    && $args[3] === 'manual';
            })
            ->once()
            ->andReturn(new SyncResult(10, 8, 2, 0, 'log', true));

        $response = $this->controller->runSync(
            new Request(query: ['dry_run' => '1'], request: ['_token' => 'good']),
            $list,
        );

        self::assertInstanceOf(RedirectResponse::class, $response);
        $flashes = $this->session->getFlashBag()->get('success');
        self::assertNotEmpty($flashes);
        self::assertStringContainsString('Dry run completed', $flashes[0]);
        self::assertStringContainsString('+2 added', $flashes[0]);
    }

    public function testRunSyncSurfacesFailureMessage(): void
    {
        $list = $this->makeList('R');
        $user = new User();

        $this->csrfTokenManager->shouldReceive('isTokenValid')->andReturn(true);

        $rateLimit = m::mock(RateLimit::class);
        $rateLimit->shouldReceive('isAccepted')->andReturn(true);
        $limiter = m::mock(LimiterInterface::class);
        $limiter->shouldReceive('consume')->andReturn($rateLimit);
        $this->syncTriggerLimiter->shouldReceive('create')->andReturn($limiter);

        $token = m::mock(TokenInterface::class);
        $token->shouldReceive('getUser')->andReturn($user);
        $this->tokenStorage->shouldReceive('getToken')->andReturn($token);

        $this->syncService->shouldReceive('executeSync')
            ->andReturn(new SyncResult(0, 0, 0, 0, '', false, 'API down'));

        $response = $this->controller->runSync(
            new Request(request: ['_token' => 'good']),
            $list,
        );

        self::assertInstanceOf(RedirectResponse::class, $response);
        $flashes = $this->session->getFlashBag()->get('danger');
        self::assertNotEmpty($flashes);
        self::assertStringContainsString('Sync failed', $flashes[0]);
        self::assertStringContainsString('API down', $flashes[0]);
    }

    public function testRunSyncSucceedsAndAnnouncesCounts(): void
    {
        $list = $this->makeList('R');
        $user = new User();

        $this->csrfTokenManager->shouldReceive('isTokenValid')->andReturn(true);

        $rateLimit = m::mock(RateLimit::class);
        $rateLimit->shouldReceive('isAccepted')->andReturn(true);
        $limiter = m::mock(LimiterInterface::class);
        $limiter->shouldReceive('consume')->andReturn($rateLimit);
        $this->syncTriggerLimiter->shouldReceive('create')->andReturn($limiter);

        $token = m::mock(TokenInterface::class);
        $token->shouldReceive('getUser')->andReturn($user);
        $this->tokenStorage->shouldReceive('getToken')->andReturn($token);

        $this->syncService->shouldReceive('executeSync')
            ->andReturn(new SyncResult(10, 8, 3, 1, 'log', true));

        $response = $this->controller->runSync(
            new Request(request: ['_token' => 'good']),
            $list,
        );

        self::assertInstanceOf(RedirectResponse::class, $response);
        $flashes = $this->session->getFlashBag()->get('success');
        self::assertNotEmpty($flashes);
        self::assertStringContainsString('Sync completed', $flashes[0]);
        self::assertStringContainsString('+3 added', $flashes[0]);
        self::assertStringContainsString('-1 removed', $flashes[0]);
    }

    // -------------------------------------------------------------------------
    // new() edge cases reachable without a real form factory
    // -------------------------------------------------------------------------

    public function testNewRedirectsToSettingsWhenNoOrganization(): void
    {
        $this->organizationRepository->shouldReceive('findOne')->andReturn(null);

        $response = $this->controller->new(new Request());

        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame('/app_settings', $response->getTargetUrl());
    }

    private function makeList(string $name): SyncList
    {
        $list = new SyncList();
        $list->setName($name);
        $list->setOrganization($this->organization);

        return $list;
    }
}
