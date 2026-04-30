<?php

namespace App\Tests\Controller;

use App\Client\Provider\ProviderInterface;
use App\Client\Provider\ProviderRegistry;
use App\Client\Provider\RefreshableProviderInterface;
use App\Controller\SyncListRefreshController;
use App\Entity\Organization;
use App\Entity\ProviderCredential;
use App\Entity\SyncList;
use Mockery as m;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Csrf\CsrfToken;

class SyncListRefreshControllerTest extends AbstractControllerTestCase
{
    private ProviderRegistry|m\LegacyMockInterface $providerRegistry;
    private LoggerInterface|m\LegacyMockInterface $logger;

    private SyncListRefreshController $controller;

    protected function setUp(): void
    {
        $this->providerRegistry = m::mock(ProviderRegistry::class);
        $this->logger = m::mock(LoggerInterface::class)->shouldIgnoreMissing();

        $this->controller = new SyncListRefreshController(
            $this->providerRegistry,
            $this->logger,
        );

        $this->controller->setContainer($this->buildBaseContainer());
    }

    public function testRefreshRejectsInvalidCsrfToken(): void
    {
        $list = $this->makeList();
        $this->csrfTokenManager->shouldReceive('isTokenValid')
            ->with(m::on(fn (CsrfToken $t) => $t->getId() === 'refresh-sync-list-'.$list->getId()))
            ->andReturn(false);
        $this->urlGenerator->shouldReceive('generate')
            ->with('app_sync_list_history', m::on(fn ($p) => $p['id'] == $list->getId()), m::any())
            ->andReturn('/lists/'.$list->getId().'/history');

        $request = Request::create('/lists/'.$list->getId().'/refresh', 'POST', ['_token' => 'bad']);

        $response = $this->controller->refresh($request, $list);

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertNotEmpty($this->flashes('danger'));
    }

    public function testRefreshWarnsWhenListHasNoSourceCredential(): void
    {
        $list = $this->makeList();
        $list->setSourceCredential(null);

        $this->csrfTokenManager->shouldReceive('isTokenValid')->andReturn(true);
        $this->urlGenerator->shouldReceive('generate')->andReturn('/back');

        $request = Request::create('/refresh', 'POST', ['_token' => 'good']);

        $response = $this->controller->refresh($request, $list);

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertNotEmpty($this->flashes('warning'));
    }

    public function testRefreshCallsProviderWhenRefreshable(): void
    {
        $org = new Organization();
        $org->setName('Org');

        $cred = new ProviderCredential();
        $cred->setOrganization($org);
        $cred->setProviderName('google_groups');

        $list = $this->makeList();
        $list->setSourceCredential($cred);
        $list->setSourceListIdentifier('group@example.com');

        $provider = m::mock(ProviderInterface::class, RefreshableProviderInterface::class);
        $provider->shouldReceive('refreshList')->with($cred, 'group@example.com')->once();

        $this->providerRegistry->shouldReceive('get')->with('google_groups')->andReturn($provider);

        $this->csrfTokenManager->shouldReceive('isTokenValid')->andReturn(true);
        $this->urlGenerator->shouldReceive('generate')->andReturn('/back');

        $request = Request::create('/refresh', 'POST', ['_token' => 'good']);

        $response = $this->controller->refresh($request, $list);

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertNotEmpty($this->flashes('success'));
    }

    public function testRefreshFallsBackToListNameWhenNoSourceListIdentifier(): void
    {
        $org = new Organization();
        $org->setName('Org');

        $cred = new ProviderCredential();
        $cred->setOrganization($org);
        $cred->setProviderName('foo');

        $list = $this->makeList();
        $list->setName('My List');
        $list->setSourceCredential($cred);

        $provider = m::mock(ProviderInterface::class, RefreshableProviderInterface::class);
        $provider->shouldReceive('refreshList')->with($cred, 'My List')->once();

        $this->providerRegistry->shouldReceive('get')->andReturn($provider);
        $this->csrfTokenManager->shouldReceive('isTokenValid')->andReturn(true);
        $this->urlGenerator->shouldReceive('generate')->andReturn('/back');

        $request = Request::create('/refresh', 'POST', ['_token' => 'good']);

        $this->controller->refresh($request, $list);
    }

    public function testRefreshWarnsWhenProviderDoesNotSupportRefresh(): void
    {
        $org = new Organization();
        $org->setName('Org');

        $cred = new ProviderCredential();
        $cred->setOrganization($org);
        $cred->setProviderName('non_refreshable');

        $list = $this->makeList();
        $list->setSourceCredential($cred);

        $provider = m::mock(ProviderInterface::class);
        $provider->shouldReceive('getDisplayName')->andReturn('Non-Refreshable');

        $this->providerRegistry->shouldReceive('get')->andReturn($provider);
        $this->csrfTokenManager->shouldReceive('isTokenValid')->andReturn(true);
        $this->urlGenerator->shouldReceive('generate')->andReturn('/back');

        $request = Request::create('/refresh', 'POST', ['_token' => 'good']);

        $response = $this->controller->refresh($request, $list);

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $warnings = $this->flashes('warning');
        $this->assertNotEmpty($warnings);
        $this->assertStringContainsString('Non-Refreshable', $warnings[0]);
    }

    public function testRefreshFlashesDangerOnException(): void
    {
        $org = new Organization();
        $org->setName('Org');

        $cred = new ProviderCredential();
        $cred->setOrganization($org);
        $cred->setProviderName('foo');

        $list = $this->makeList();
        $list->setName('Listy');
        $list->setSourceCredential($cred);
        $list->setSourceListIdentifier('id');

        $provider = m::mock(ProviderInterface::class, RefreshableProviderInterface::class);
        $provider->shouldReceive('refreshList')->andThrow(new \RuntimeException('API down'));

        $this->providerRegistry->shouldReceive('get')->andReturn($provider);
        $this->csrfTokenManager->shouldReceive('isTokenValid')->andReturn(true);
        $this->urlGenerator->shouldReceive('generate')->andReturn('/back');

        $request = Request::create('/refresh', 'POST', ['_token' => 'good']);

        $response = $this->controller->refresh($request, $list);

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $errors = $this->flashes('danger');
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('API down', $errors[0]);
    }

    private function makeList(): SyncList
    {
        $org = new Organization();
        $org->setName('Org');

        $list = new SyncList();
        $list->setOrganization($org);
        $list->setName('Test list');

        return $list;
    }
}
