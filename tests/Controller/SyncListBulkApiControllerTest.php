<?php

namespace App\Tests\Controller;

use App\Controller\SyncListBulkApiController;
use App\Entity\Organization;
use App\Entity\SyncList;
use App\Repository\OrganizationRepository;
use App\Repository\SyncListRepository;
use Doctrine\ORM\EntityManagerInterface;
use Mockery\Adapter\Phpunit\MockeryTestCase;
use Mockery as m;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

class SyncListBulkApiControllerTest extends MockeryTestCase
{
    private OrganizationRepository|m\LegacyMockInterface $organizationRepository;
    private SyncListRepository|m\LegacyMockInterface $syncListRepository;
    private EntityManagerInterface|m\LegacyMockInterface $entityManager;
    private CsrfTokenManagerInterface|m\LegacyMockInterface $csrfTokenManager;

    private Organization $organization;
    private SyncListBulkApiController $controller;

    protected function setUp(): void
    {
        $this->organizationRepository = m::mock(OrganizationRepository::class);
        $this->syncListRepository = m::mock(SyncListRepository::class);
        $this->entityManager = m::mock(EntityManagerInterface::class);
        $this->csrfTokenManager = m::mock(CsrfTokenManagerInterface::class);

        $this->organization = new Organization();
        $this->organization->setName('Test Org');

        $this->controller = new SyncListBulkApiController(
            $this->organizationRepository,
            $this->syncListRepository,
            $this->entityManager,
        );

        // Inject the CSRF token manager via the container
        $container = new \Symfony\Component\DependencyInjection\Container();
        $container->set('security.csrf.token_manager', $this->csrfTokenManager);
        $this->controller->setContainer($container);
    }

    public function testActivateRejectsMissingCsrfToken(): void
    {
        $this->csrfTokenManager->shouldReceive('isTokenValid')
            ->with(m::on(fn (CsrfToken $token) => $token->getId() === 'bulk-actions' && $token->getValue() === ''))
            ->andReturn(false);

        $request = $this->makeRequest([]);

        $response = $this->controller->activate($request);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertStringContainsString('CSRF', $this->decodeResponse($response)['error']);
    }

    public function testActivateRejectsInvalidCsrfToken(): void
    {
        $this->csrfTokenManager->shouldReceive('isTokenValid')
            ->andReturn(false);

        $request = $this->makeRequest([], 'bad-token');

        $response = $this->controller->activate($request);

        $this->assertSame(403, $response->getStatusCode());
    }

    public function testActivateRejectsMissingIds(): void
    {
        $this->validCsrf();

        $request = $this->makeRequest([], 'valid-token');

        $response = $this->controller->activate($request);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertStringContainsString('ids', $this->decodeResponse($response)['error']);
    }

    public function testActivateRejectsEmptyIds(): void
    {
        $this->validCsrf();

        $request = $this->makeRequest(['ids' => []], 'valid-token');

        $response = $this->controller->activate($request);

        $this->assertSame(400, $response->getStatusCode());
    }

    public function testActivateEnablesLists(): void
    {
        $this->validCsrf();

        $list1 = new SyncList();
        $list1->setName('List 1');
        $list1->setOrganization($this->organization);
        $list1->setIsEnabled(false);

        $list2 = new SyncList();
        $list2->setName('List 2');
        $list2->setOrganization($this->organization);
        $list2->setIsEnabled(false);

        $this->organizationRepository->shouldReceive('findOne')
            ->andReturn($this->organization);
        $this->syncListRepository->shouldReceive('findByOrganizationAndIds')
            ->with($this->organization, ['id-1', 'id-2'])
            ->andReturn([$list1, $list2]);
        $this->entityManager->shouldReceive('flush')->once();

        $request = $this->makeRequest(['ids' => ['id-1', 'id-2']], 'valid-token');

        $response = $this->controller->activate($request);

        $this->assertSame(200, $response->getStatusCode());
        $data = $this->decodeResponse($response);
        $this->assertTrue($data['success']);
        $this->assertSame(2, $data['updated']);
        $this->assertTrue($list1->isEnabled());
        $this->assertTrue($list2->isEnabled());
    }

    public function testDeactivateDisablesLists(): void
    {
        $this->validCsrf();

        $list1 = new SyncList();
        $list1->setName('List 1');
        $list1->setOrganization($this->organization);
        $list1->setIsEnabled(true);

        $this->organizationRepository->shouldReceive('findOne')
            ->andReturn($this->organization);
        $this->syncListRepository->shouldReceive('findByOrganizationAndIds')
            ->andReturn([$list1]);
        $this->entityManager->shouldReceive('flush')->once();

        $request = $this->makeRequest(['ids' => ['id-1']], 'valid-token');

        $response = $this->controller->deactivate($request);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertFalse($list1->isEnabled());
    }

    public function testScheduleSetsValidCron(): void
    {
        $this->validCsrf();

        $list1 = new SyncList();
        $list1->setName('List 1');
        $list1->setOrganization($this->organization);

        $this->organizationRepository->shouldReceive('findOne')
            ->andReturn($this->organization);
        $this->syncListRepository->shouldReceive('findByOrganizationAndIds')
            ->andReturn([$list1]);
        $this->entityManager->shouldReceive('flush')->once();

        $request = $this->makeRequest(
            ['ids' => ['id-1'], 'cronExpression' => '*/30 * * * *'],
            'valid-token',
        );

        $response = $this->controller->schedule($request);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('*/30 * * * *', $list1->getCronExpression());
    }

    public function testScheduleRejectsInvalidCron(): void
    {
        $this->validCsrf();

        $request = $this->makeRequest(
            ['ids' => ['id-1'], 'cronExpression' => 'not-a-cron'],
            'valid-token',
        );

        $response = $this->controller->schedule($request);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertStringContainsString('cron', $this->decodeResponse($response)['error']);
    }

    public function testScheduleClearsCronWithEmptyString(): void
    {
        $this->validCsrf();

        $list1 = new SyncList();
        $list1->setName('List 1');
        $list1->setOrganization($this->organization);
        $list1->setCronExpression('0 * * * *');

        $this->organizationRepository->shouldReceive('findOne')
            ->andReturn($this->organization);
        $this->syncListRepository->shouldReceive('findByOrganizationAndIds')
            ->andReturn([$list1]);
        $this->entityManager->shouldReceive('flush')->once();

        $request = $this->makeRequest(
            ['ids' => ['id-1'], 'cronExpression' => ''],
            'valid-token',
        );

        $response = $this->controller->schedule($request);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertNull($list1->getCronExpression());
    }

    public function testActivateWithNoOrganizationUpdatesNothing(): void
    {
        $this->validCsrf();

        $this->organizationRepository->shouldReceive('findOne')
            ->andReturn(null);
        $this->entityManager->shouldReceive('flush')->once();

        $request = $this->makeRequest(['ids' => ['id-1']], 'valid-token');

        $response = $this->controller->activate($request);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(0, $this->decodeResponse($response)['updated']);
    }

    private function makeRequest(array $body, string $csrfToken = ''): Request
    {
        $request = Request::create('/', 'POST', [], [], [], [], json_encode($body));
        $request->headers->set('X-CSRF-Token', $csrfToken);
        $request->headers->set('Content-Type', 'application/json');

        return $request;
    }

    private function validCsrf(): void
    {
        $this->csrfTokenManager->shouldReceive('isTokenValid')
            ->with(m::on(fn (CsrfToken $token) => $token->getId() === 'bulk-actions' && $token->getValue() === 'valid-token'))
            ->andReturn(true);
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeResponse(\Symfony\Component\HttpFoundation\JsonResponse $response): array
    {
        return json_decode($response->getContent(), true);
    }
}
