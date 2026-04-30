<?php

namespace App\Tests\Controller;

use App\Controller\SyncRunApiController;
use App\Entity\Organization;
use App\Entity\SyncList;
use App\Entity\SyncRun;
use App\Entity\User;
use App\Notification\SyncNotificationService;
use App\Sync\SyncResult;
use App\Sync\SyncService;
use Mockery as m;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Csrf\CsrfToken;

class SyncRunApiControllerTest extends AbstractControllerTestCase
{
    private SyncService|m\LegacyMockInterface $syncService;
    private SyncNotificationService|m\LegacyMockInterface $notificationService;

    private SyncRunApiController $controller;

    protected function setUp(): void
    {
        $this->syncService = m::mock(SyncService::class);
        $this->notificationService = m::mock(SyncNotificationService::class);

        $this->controller = new SyncRunApiController(
            $this->syncService,
            $this->notificationService,
        );

        $this->controller->setContainer($this->buildBaseContainer());
    }

    public function testSyncRejectsInvalidCsrfToken(): void
    {
        $list = $this->makeList();
        $this->csrfTokenManager->shouldReceive('isTokenValid')
            ->with(m::on(fn (CsrfToken $t) => $t->getId() === 'sync-run-all'))
            ->andReturn(false);

        $request = new Request();
        $request->headers->set('X-CSRF-Token', 'bad');

        $response = $this->controller->sync($request, $list);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertStringContainsString('CSRF', $response->getContent());
    }

    public function testSyncExecutesAndReturnsJsonOnSuccess(): void
    {
        $list = $this->makeList();
        $list->setName('Group A');

        $user = $this->makeUser();
        $this->setUser($user);

        $this->csrfTokenManager->shouldReceive('isTokenValid')->andReturn(true);

        $syncRun = m::mock(SyncRun::class);
        $result = new SyncResult(
            sourceCount: 10,
            destinationCount: 8,
            addedCount: 2,
            removedCount: 1,
            log: '',
            success: true,
            errorMessage: null,
            syncRun: $syncRun,
        );

        $this->syncService->shouldReceive('executeSync')->andReturn($result);
        $this->notificationService->shouldReceive('sendBatchNotification')->with([$syncRun])->once();

        $request = new Request();
        $request->headers->set('X-CSRF-Token', 'good');

        $response = $this->controller->sync($request, $list);

        $this->assertSame(200, $response->getStatusCode());
        $body = json_decode($response->getContent(), true);
        $this->assertTrue($body['success']);
        $this->assertSame('Group A', $body['listName']);
        $this->assertSame(2, $body['addedCount']);
        $this->assertSame(1, $body['removedCount']);
        $this->assertNull($body['errorMessage']);
    }

    public function testSyncReturnsErrorMessageOnFailure(): void
    {
        $list = $this->makeList();
        $list->setName('FailList');

        $user = $this->makeUser();
        $this->setUser($user);

        $this->csrfTokenManager->shouldReceive('isTokenValid')->andReturn(true);

        $result = new SyncResult(
            sourceCount: 0,
            destinationCount: 0,
            addedCount: 0,
            removedCount: 0,
            log: '',
            success: false,
            errorMessage: 'Provider unreachable',
            syncRun: null,
        );

        $this->syncService->shouldReceive('executeSync')->andReturn($result);
        $this->notificationService->shouldNotReceive('sendBatchNotification');

        $request = new Request();
        $request->headers->set('X-CSRF-Token', 'good');

        $response = $this->controller->sync($request, $list);

        $this->assertSame(200, $response->getStatusCode());
        $body = json_decode($response->getContent(), true);
        $this->assertFalse($body['success']);
        $this->assertSame('Provider unreachable', $body['errorMessage']);
    }

    public function testStatusReturnsSyncRunFields(): void
    {
        $user = $this->makeUser();

        $syncRun = m::mock(SyncRun::class);
        $syncRun->shouldReceive('getId')->andReturn(\Symfony\Component\Uid\Uuid::v7());
        $syncRun->shouldReceive('getStatus')->andReturn('success');
        $syncRun->shouldReceive('getSourceCount')->andReturn(50);
        $syncRun->shouldReceive('getDestinationCount')->andReturn(48);
        $syncRun->shouldReceive('getAddedCount')->andReturn(3);
        $syncRun->shouldReceive('getRemovedCount')->andReturn(1);
        $syncRun->shouldReceive('getDurationSeconds')->andReturn(2.5);
        $syncRun->shouldReceive('getStartedAt')->andReturn(new \DateTimeImmutable('2026-01-01 12:00:00'));
        $syncRun->shouldReceive('getCompletedAt')->andReturn(new \DateTimeImmutable('2026-01-01 12:00:02'));
        $syncRun->shouldReceive('getCreatedAt')->andReturn(new \DateTimeImmutable('2026-01-01 11:59:59'));
        $syncRun->shouldReceive('getTriggeredBy')->andReturn('manual');
        $syncRun->shouldReceive('getTriggeredByUser')->andReturn($user);
        $syncRun->shouldReceive('getErrorMessage')->andReturn(null);

        $response = $this->controller->status($syncRun);

        $this->assertSame(200, $response->getStatusCode());
        $body = json_decode($response->getContent(), true);
        $this->assertSame('success', $body['status']);
        $this->assertSame(50, $body['sourceCount']);
        $this->assertSame(2.5, $body['durationSeconds']);
        $this->assertSame('First Last', $body['triggeredByUser']);
    }

    public function testStatusHandlesNullableTimestamps(): void
    {
        $syncRun = m::mock(SyncRun::class);
        $syncRun->shouldReceive('getId')->andReturn(\Symfony\Component\Uid\Uuid::v7());
        $syncRun->shouldReceive('getStatus')->andReturn('pending');
        $syncRun->shouldReceive('getSourceCount')->andReturn(null);
        $syncRun->shouldReceive('getDestinationCount')->andReturn(null);
        $syncRun->shouldReceive('getAddedCount')->andReturn(null);
        $syncRun->shouldReceive('getRemovedCount')->andReturn(null);
        $syncRun->shouldReceive('getDurationSeconds')->andReturn(null);
        $syncRun->shouldReceive('getStartedAt')->andReturn(null);
        $syncRun->shouldReceive('getCompletedAt')->andReturn(null);
        $syncRun->shouldReceive('getCreatedAt')->andReturn(new \DateTimeImmutable());
        $syncRun->shouldReceive('getTriggeredBy')->andReturn('scheduled');
        $syncRun->shouldReceive('getTriggeredByUser')->andReturn(null);
        $syncRun->shouldReceive('getErrorMessage')->andReturn(null);

        $response = $this->controller->status($syncRun);

        $body = json_decode($response->getContent(), true);
        $this->assertNull($body['startedAt']);
        $this->assertNull($body['completedAt']);
        $this->assertNull($body['triggeredByUser']);
    }

    private function makeList(): SyncList
    {
        $org = new Organization();
        $org->setName('Org');

        $list = new SyncList();
        $list->setOrganization($org);
        $list->setName('A list');

        return $list;
    }

    private function makeUser(): User
    {
        $u = new User();
        $u->setEmail('me@example.com');
        $u->setFirstName('First');
        $u->setLastName('Last');

        return $u;
    }
}
