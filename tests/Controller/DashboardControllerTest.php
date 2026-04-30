<?php

namespace App\Tests\Controller;

use App\Controller\DashboardController;
use App\Entity\Organization;
use App\Entity\SyncList;
use App\Entity\User;
use App\Notification\SyncNotificationService;
use App\Repository\OrganizationRepository;
use App\Repository\SyncListRepository;
use App\Repository\SyncRunRepository;
use App\Sync\SyncResult;
use App\Sync\SyncService;
use Mockery\Adapter\Phpunit\MockeryTestCase;
use Mockery as m;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Twig\Environment;

class DashboardControllerTest extends MockeryTestCase
{
    private OrganizationRepository|m\LegacyMockInterface $organizationRepository;
    private SyncListRepository|m\LegacyMockInterface $syncListRepository;
    private SyncRunRepository|m\LegacyMockInterface $syncRunRepository;
    private SyncService|m\LegacyMockInterface $syncService;
    private SyncNotificationService|m\LegacyMockInterface $notificationService;
    private Environment|m\LegacyMockInterface $twig;
    private CsrfTokenManagerInterface|m\LegacyMockInterface $csrfTokenManager;
    private TokenStorageInterface|m\LegacyMockInterface $tokenStorage;
    private UrlGeneratorInterface|m\LegacyMockInterface $urlGenerator;

    private DashboardController $controller;
    private Session $session;

    protected function setUp(): void
    {
        $this->organizationRepository = m::mock(OrganizationRepository::class);
        $this->syncListRepository = m::mock(SyncListRepository::class);
        $this->syncRunRepository = m::mock(SyncRunRepository::class);
        $this->syncService = m::mock(SyncService::class);
        $this->notificationService = m::mock(SyncNotificationService::class);
        $this->twig = m::mock(Environment::class);
        $this->csrfTokenManager = m::mock(CsrfTokenManagerInterface::class);
        $this->tokenStorage = m::mock(TokenStorageInterface::class);
        $this->urlGenerator = m::mock(UrlGeneratorInterface::class);

        $this->session = new Session(new MockArraySessionStorage());
        $requestStack = new RequestStack();
        $request = new Request();
        $request->setSession($this->session);
        $requestStack->push($request);

        $this->controller = new DashboardController(
            $this->organizationRepository,
            $this->syncListRepository,
            $this->syncRunRepository,
            $this->syncService,
            $this->notificationService,
        );

        $container = new Container();
        $container->set('twig', $this->twig);
        $container->set('request_stack', $requestStack);
        $container->set('security.csrf.token_manager', $this->csrfTokenManager);
        $container->set('security.token_storage', $this->tokenStorage);
        $container->set('router', $this->urlGenerator);
        $this->controller->setContainer($container);
    }

    public function testIndexWithoutOrganizationRendersEmptyDashboard(): void
    {
        $this->organizationRepository->shouldReceive('findOne')->andReturn(null);

        $this->twig->shouldReceive('render')
            ->with('dashboard/index.html.twig', m::on(function (array $params): bool {
                return $params['organization'] === null
                    && $params['totalLists'] === 0
                    && $params['enabledLists'] === 0
                    && $params['enabledSyncLists'] === []
                    && $params['lastSyncRun'] === null
                    && $params['nextScheduledSync'] === null
                    && $params['recentSyncRuns'] === [];
            }))
            ->andReturn('rendered');

        $response = $this->controller->index();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('rendered', $response->getContent());
    }

    public function testIndexWithOrganizationRendersStats(): void
    {
        $organization = new Organization();
        $organization->setName('Test Org');

        $list = new SyncList();
        $list->setName('My list');
        $list->setOrganization($organization);

        $this->organizationRepository->shouldReceive('findOne')->andReturn($organization);
        $this->syncListRepository->shouldReceive('countByOrganization')->with($organization)->andReturn(5);
        $this->syncListRepository->shouldReceive('countEnabledByOrganization')->with($organization)->andReturn(3);
        $this->syncListRepository->shouldReceive('findEnabledByOrganization')->with($organization)->andReturn([$list]);
        $this->syncRunRepository->shouldReceive('findLastCompletedByOrganization')->with($organization)->andReturn(null);
        $this->syncRunRepository->shouldReceive('findRecentByOrganization')->with($organization, 10)->andReturn([]);

        $this->twig->shouldReceive('render')
            ->with('dashboard/index.html.twig', m::on(function (array $params) use ($organization): bool {
                return $params['organization'] === $organization
                    && $params['totalLists'] === 5
                    && $params['enabledLists'] === 3;
            }))
            ->andReturn('ok');

        $response = $this->controller->index();

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testIndexComputesNextScheduledSyncFromCronExpressions(): void
    {
        $organization = new Organization();
        $organization->setName('Test Org');

        $list1 = new SyncList();
        $list1->setName('Hourly');
        $list1->setOrganization($organization);
        $list1->setCronExpression('0 * * * *'); // hourly

        $list2 = new SyncList();
        $list2->setName('Invalid');
        $list2->setOrganization($organization);
        $list2->setCronExpression('not-a-cron');

        $list3 = new SyncList();
        $list3->setName('No cron');
        $list3->setOrganization($organization);

        $this->organizationRepository->shouldReceive('findOne')->andReturn($organization);
        $this->syncListRepository->shouldReceive('countByOrganization')->andReturn(3);
        $this->syncListRepository->shouldReceive('countEnabledByOrganization')->andReturn(3);
        $this->syncListRepository->shouldReceive('findEnabledByOrganization')->andReturn([$list1, $list2, $list3]);
        $this->syncRunRepository->shouldReceive('findLastCompletedByOrganization')->andReturn(null);
        $this->syncRunRepository->shouldReceive('findRecentByOrganization')->andReturn([]);

        $captured = [];
        $this->twig->shouldReceive('render')
            ->with('dashboard/index.html.twig', m::on(function (array $params) use (&$captured): bool {
                $captured = $params;

                return true;
            }))
            ->andReturn('ok');

        $this->controller->index();

        $this->assertIsArray($captured['nextScheduledSync']);
        $this->assertSame('Hourly', $captured['nextScheduledSync']['listName']);
        $this->assertInstanceOf(\DateTimeImmutable::class, $captured['nextScheduledSync']['time']);
    }

    public function testRunAllRejectsInvalidCsrfToken(): void
    {
        $this->csrfTokenManager->shouldReceive('isTokenValid')
            ->with(m::on(fn (CsrfToken $t) => $t->getId() === 'sync-run-all'))
            ->andReturn(false);
        $this->urlGenerator->shouldReceive('generate')->with('app_dashboard', m::any(), m::any())->andReturn('/');

        $request = Request::create('/sync/run-all', 'POST', ['_token' => 'bad']);

        $response = $this->controller->runAll($request);

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertSame('/', $response->getTargetUrl());
        $this->assertContains('Invalid CSRF token.', $this->session->getFlashBag()->get('danger'));
    }

    public function testRunAllRedirectsToSettingsWhenNoOrganization(): void
    {
        $this->csrfTokenManager->shouldReceive('isTokenValid')->andReturn(true);
        $this->organizationRepository->shouldReceive('findOne')->andReturn(null);
        $this->urlGenerator->shouldReceive('generate')->with('app_settings', m::any(), m::any())->andReturn('/settings');

        $request = Request::create('/sync/run-all', 'POST', ['_token' => 'good']);

        $response = $this->controller->runAll($request);

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertSame('/settings', $response->getTargetUrl());
        $this->assertNotEmpty($this->session->getFlashBag()->get('warning'));
    }

    public function testRunAllNoEnabledListsAddsInfoFlash(): void
    {
        $organization = new Organization();
        $organization->setName('Test');

        $this->csrfTokenManager->shouldReceive('isTokenValid')->andReturn(true);
        $this->organizationRepository->shouldReceive('findOne')->andReturn($organization);
        $this->syncListRepository->shouldReceive('findEnabledByOrganization')->andReturn([]);
        $this->urlGenerator->shouldReceive('generate')->with('app_dashboard', m::any(), m::any())->andReturn('/');

        $request = Request::create('/sync/run-all', 'POST', ['_token' => 'good']);

        $response = $this->controller->runAll($request);

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertNotEmpty($this->session->getFlashBag()->get('info'));
    }

    public function testRunAllExecutesSyncOnAllEnabledListsAndSendsBatchNotification(): void
    {
        $organization = new Organization();
        $organization->setName('Test');

        $list1 = new SyncList();
        $list1->setName('A');
        $list1->setOrganization($organization);

        $list2 = new SyncList();
        $list2->setName('B');
        $list2->setOrganization($organization);

        $user = new User();
        $user->setEmail('me@example.com');
        $user->setFirstName('A');
        $user->setLastName('B');

        $token = m::mock(TokenInterface::class);
        $token->shouldReceive('getUser')->andReturn($user);
        $this->tokenStorage->shouldReceive('getToken')->andReturn($token);

        $this->csrfTokenManager->shouldReceive('isTokenValid')->andReturn(true);
        $this->organizationRepository->shouldReceive('findOne')->andReturn($organization);
        $this->syncListRepository->shouldReceive('findEnabledByOrganization')->andReturn([$list1, $list2]);

        $syncRun1 = m::mock(\App\Entity\SyncRun::class);
        $syncRun2 = m::mock(\App\Entity\SyncRun::class);
        $result1 = new SyncResult(0, 0, 0, 0, '', true, null, $syncRun1);
        $result2 = new SyncResult(0, 0, 0, 0, '', false, 'oops', $syncRun2);

        $this->syncService->shouldReceive('executeSync')
            ->andReturn($result1, $result2);

        $this->notificationService->shouldReceive('sendBatchNotification')
            ->once()
            ->with([$syncRun1, $syncRun2]);

        $this->urlGenerator->shouldReceive('generate')->with('app_dashboard', m::any(), m::any())->andReturn('/');

        $request = Request::create('/sync/run-all', 'POST', ['_token' => 'good']);

        $response = $this->controller->runAll($request);

        $this->assertInstanceOf(RedirectResponse::class, $response);
        // 1 success + 1 failure → warning flash
        $this->assertNotEmpty($this->session->getFlashBag()->get('warning'));
    }
}
