<?php

namespace App\Tests\Controller;

use App\Controller\SyncHistoryController;
use App\Entity\Organization;
use App\Entity\SyncList;
use App\Entity\SyncRun;
use App\Repository\OrganizationRepository;
use App\Repository\SyncListRepository;
use App\Repository\SyncRunRepository;
use Mockery as m;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;

class SyncHistoryControllerTest extends AbstractControllerTestCase
{
    private OrganizationRepository|m\LegacyMockInterface $organizationRepository;
    private SyncRunRepository|m\LegacyMockInterface $syncRunRepository;
    private SyncListRepository|m\LegacyMockInterface $syncListRepository;

    private SyncHistoryController $controller;

    protected function setUp(): void
    {
        $this->organizationRepository = m::mock(OrganizationRepository::class);
        $this->syncRunRepository = m::mock(SyncRunRepository::class);
        $this->syncListRepository = m::mock(SyncListRepository::class);

        $this->controller = new SyncHistoryController(
            $this->organizationRepository,
            $this->syncRunRepository,
            $this->syncListRepository,
        );

        $this->controller->setContainer($this->buildBaseContainer());
    }

    public function testIndexRedirectsToSettingsWhenNoOrganization(): void
    {
        $this->organizationRepository->shouldReceive('findOne')->andReturn(null);
        $this->expectRoute('app_settings', '/settings');

        $response = $this->controller->index(new Request());

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertSame('/settings', $response->getTargetUrl());
        $this->assertNotEmpty($this->flashes('warning'));
    }

    public function testIndexPassesPaginationDefaults(): void
    {
        $org = new Organization();
        $org->setName('Org');
        $this->organizationRepository->shouldReceive('findOne')->andReturn($org);

        $this->syncRunRepository->shouldReceive('findByOrganizationPaginated')
            ->with($org, 25, 0, null, null, false)
            ->andReturn([]);
        $this->syncRunRepository->shouldReceive('countByOrganization')
            ->with($org, null, null, false)
            ->andReturn(0);
        $this->syncListRepository->shouldReceive('findByOrganization')->with($org)->andReturn([]);

        $captured = [];
        $this->expectRender('sync_history/index.html.twig', function (array $params) use (&$captured): bool {
            $captured = $params;

            return true;
        });

        $this->controller->index(new Request());

        $this->assertSame(1, $captured['page']);
        $this->assertSame(0, $captured['total_count']);
        $this->assertSame(1, $captured['total_pages']);
    }

    public function testIndexComputesPaginationOffsetForLaterPages(): void
    {
        $org = new Organization();
        $org->setName('Org');
        $this->organizationRepository->shouldReceive('findOne')->andReturn($org);

        $this->syncRunRepository->shouldReceive('findByOrganizationPaginated')
            ->with($org, 25, 50, null, null, false)
            ->andReturn([]);
        $this->syncRunRepository->shouldReceive('countByOrganization')
            ->with($org, null, null, false)
            ->andReturn(60);
        $this->syncListRepository->shouldReceive('findByOrganization')->with($org)->andReturn([]);

        $captured = [];
        $this->expectRender('sync_history/index.html.twig', function (array $params) use (&$captured): bool {
            $captured = $params;

            return true;
        });

        $request = new Request(['page' => 3]);

        $this->controller->index($request);

        $this->assertSame(3, $captured['page']);
        $this->assertSame(3, $captured['total_pages']); // ceil(60/25)
    }

    public function testIndexAppliesFilters(): void
    {
        $org = new Organization();
        $org->setName('Org');

        $list = new SyncList();
        $list->setName('List');
        $list->setOrganization($org);

        $this->organizationRepository->shouldReceive('findOne')->andReturn($org);
        $this->syncListRepository->shouldReceive('find')->with('list-id-1')->andReturn($list);
        $this->syncListRepository->shouldReceive('findByOrganization')->andReturn([$list]);

        $this->syncRunRepository->shouldReceive('findByOrganizationPaginated')
            ->with($org, 25, 0, $list, 'success', true)
            ->andReturn([]);
        $this->syncRunRepository->shouldReceive('countByOrganization')
            ->with($org, $list, 'success', true)
            ->andReturn(0);

        $captured = [];
        $this->expectRender('sync_history/index.html.twig', function (array $params) use (&$captured): bool {
            $captured = $params;

            return true;
        });

        $request = new Request([
            'list' => 'list-id-1',
            'status' => 'success',
            'changes' => '1',
        ]);

        $this->controller->index($request);

        $this->assertSame($list, $captured['current_list']);
        $this->assertSame('success', $captured['current_status']);
        $this->assertTrue($captured['current_changes']);
    }

    public function testIndexIgnoresUnknownStatusFilter(): void
    {
        $org = new Organization();
        $org->setName('Org');
        $this->organizationRepository->shouldReceive('findOne')->andReturn($org);
        $this->syncListRepository->shouldReceive('findByOrganization')->andReturn([]);

        $this->syncRunRepository->shouldReceive('findByOrganizationPaginated')
            ->with($org, 25, 0, null, null, false)
            ->andReturn([]);
        $this->syncRunRepository->shouldReceive('countByOrganization')
            ->with($org, null, null, false)
            ->andReturn(0);

        $captured = [];
        $this->expectRender('sync_history/index.html.twig', function (array $params) use (&$captured): bool {
            $captured = $params;

            return true;
        });

        $request = new Request(['status' => 'bogus-status']);

        $this->controller->index($request);

        $this->assertNull($captured['current_status']);
    }

    public function testIndexClampsZeroOrNegativePageToOne(): void
    {
        $org = new Organization();
        $org->setName('Org');
        $this->organizationRepository->shouldReceive('findOne')->andReturn($org);
        $this->syncListRepository->shouldReceive('findByOrganization')->andReturn([]);

        $this->syncRunRepository->shouldReceive('findByOrganizationPaginated')
            ->with($org, 25, 0, null, null, false)
            ->andReturn([]);
        $this->syncRunRepository->shouldReceive('countByOrganization')->andReturn(0);

        $captured = [];
        $this->expectRender('sync_history/index.html.twig', function (array $params) use (&$captured): bool {
            $captured = $params;

            return true;
        });

        $request = new Request(['page' => -5]);

        $this->controller->index($request);

        $this->assertSame(1, $captured['page']);
    }

    public function testShowRendersSyncRun(): void
    {
        $syncRun = m::mock(SyncRun::class);

        $captured = [];
        $this->expectRender('sync_history/show.html.twig', function (array $params) use (&$captured): bool {
            $captured = $params;

            return true;
        });

        $response = $this->controller->show($syncRun);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame($syncRun, $captured['sync_run']);
    }
}
