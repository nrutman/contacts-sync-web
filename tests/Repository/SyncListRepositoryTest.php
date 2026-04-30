<?php

namespace App\Tests\Repository;

use App\Entity\Organization;
use App\Entity\SyncList;
use App\Repository\SyncListRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Mockery\Adapter\Phpunit\MockeryTestCase;
use Mockery as m;
use Symfony\Component\Uid\Uuid;

class SyncListRepositoryTest extends MockeryTestCase
{
    private EntityManagerInterface|m\LegacyMockInterface $entityManager;
    private SyncListRepository $repository;
    private Organization $organization;

    protected function setUp(): void
    {
        $this->entityManager = m::mock(EntityManagerInterface::class);

        // ServiceEntityRepository hydrates the EntityManager via the ManagerRegistry.
        $registry = m::mock(ManagerRegistry::class);
        $registry->shouldReceive('getManagerForClass')
            ->with(SyncList::class)
            ->andReturn($this->entityManager);

        // EntityRepository's parent ctor invokes EM::getClassMetadata(). Returning
        // a real ClassMetadata keeps things simple (no need to mock setName etc.).
        $metadata = new ClassMetadata(SyncList::class);
        $metadata->name = SyncList::class;
        $this->entityManager->shouldReceive('getClassMetadata')
            ->with(SyncList::class)
            ->andReturn($metadata);

        $this->repository = new SyncListRepository($registry);

        $this->organization = new Organization();
        $this->organization->setName('Test');
    }

    // -------------------------------------------------------------------------
    // findByOrganization()
    // -------------------------------------------------------------------------

    public function testFindByOrganizationFiltersByOrgIdAndOrdersByName(): void
    {
        $expected = [new SyncList(), new SyncList()];

        $qb = $this->expectQueryBuilder();
        $qb->shouldReceive('where')->once()->with('sl.organization = :org')->andReturnSelf();
        $qb->shouldReceive('setParameter')->once()->with('org', $this->organization->getId())->andReturnSelf();
        $qb->shouldReceive('orderBy')->once()->with('sl.name', 'ASC')->andReturnSelf();
        $this->expectQueryResult($qb, $expected);

        self::assertSame($expected, $this->repository->findByOrganization($this->organization));
    }

    // -------------------------------------------------------------------------
    // findEnabledByOrganization()
    // -------------------------------------------------------------------------

    public function testFindEnabledByOrganizationFiltersByOrgAndEnabledFlag(): void
    {
        $orgIdCaptured = null;
        $enabledCaptured = null;

        $qb = $this->expectQueryBuilder();
        $qb->shouldReceive('where')->once()->with('sl.organization = :org')->andReturnSelf();
        $qb->shouldReceive('andWhere')->once()->with('sl.isEnabled = :enabled')->andReturnSelf();
        $qb->shouldReceive('setParameter')
            ->andReturnUsing(function (string $key, $value) use (&$orgIdCaptured, &$enabledCaptured, $qb) {
                if ($key === 'org') {
                    $orgIdCaptured = $value;
                } elseif ($key === 'enabled') {
                    $enabledCaptured = $value;
                }

                return $qb;
            });
        $qb->shouldReceive('orderBy')->once()->with('sl.name', 'ASC')->andReturnSelf();

        $expected = [new SyncList()];
        $this->expectQueryResult($qb, $expected);

        self::assertSame($expected, $this->repository->findEnabledByOrganization($this->organization));
        self::assertEquals($this->organization->getId(), $orgIdCaptured);
        self::assertTrue($enabledCaptured);
    }

    // -------------------------------------------------------------------------
    // findByOrganizationAndIds()  -- the multi-tenant safety net
    // -------------------------------------------------------------------------

    public function testFindByOrganizationAndIdsReturnsEmptyArrayShortCircuitWhenIdsEmpty(): void
    {
        // No QueryBuilder expectations at all: an empty id list must short-circuit
        // without ever touching the database.
        $this->entityManager->shouldNotReceive('createQueryBuilder');

        self::assertSame([], $this->repository->findByOrganizationAndIds($this->organization, []));
    }

    public function testFindByOrganizationAndIdsAppliesBothOrgAndIdsFilters(): void
    {
        $ids = [(string) Uuid::v7(), (string) Uuid::v7()];

        $orgIdCaptured = null;
        $idsCaptured = null;

        $qb = $this->expectQueryBuilder();
        $qb->shouldReceive('where')->once()->with('sl.organization = :org')->andReturnSelf();
        $qb->shouldReceive('andWhere')->once()->with('sl.id IN (:ids)')->andReturnSelf();
        $qb->shouldReceive('setParameter')
            ->andReturnUsing(function (string $key, $value) use (&$orgIdCaptured, &$idsCaptured, $qb) {
                if ($key === 'org') {
                    $orgIdCaptured = $value;
                } elseif ($key === 'ids') {
                    $idsCaptured = $value;
                }

                return $qb;
            });

        $expected = [new SyncList()];
        $this->expectQueryResult($qb, $expected);

        $result = $this->repository->findByOrganizationAndIds($this->organization, $ids);

        self::assertSame($expected, $result);
        // Cross-org leak guard: org id MUST be passed through.
        self::assertEquals($this->organization->getId(), $orgIdCaptured);
        self::assertSame($ids, $idsCaptured);
    }

    public function testFindByOrganizationAndIdsReturnsEmptyArrayWhenQueryReturnsNothing(): void
    {
        $qb = $this->expectQueryBuilder();
        $qb->shouldReceive('where')->andReturnSelf();
        $qb->shouldReceive('andWhere')->andReturnSelf();
        $qb->shouldReceive('setParameter')->andReturnSelf();
        $this->expectQueryResult($qb, []);

        self::assertSame(
            [],
            $this->repository->findByOrganizationAndIds($this->organization, ['no-such-id']),
        );
    }

    // -------------------------------------------------------------------------
    // countByOrganization() / countEnabledByOrganization()
    // -------------------------------------------------------------------------

    public function testCountByOrganizationReturnsScalarCastToInt(): void
    {
        $qb = $this->expectQueryBuilder();
        $qb->shouldReceive('select')->once()->with('COUNT(sl.id)')->andReturnSelf();
        $qb->shouldReceive('where')->once()->with('sl.organization = :org')->andReturnSelf();
        $qb->shouldReceive('setParameter')->once()->with('org', $this->organization->getId())->andReturnSelf();
        $this->expectScalarQueryResult($qb, '7');

        self::assertSame(7, $this->repository->countByOrganization($this->organization));
    }

    public function testCountEnabledByOrganizationFiltersOnEnabledFlag(): void
    {
        $enabledCaptured = null;

        $qb = $this->expectQueryBuilder();
        $qb->shouldReceive('select')->once()->with('COUNT(sl.id)')->andReturnSelf();
        $qb->shouldReceive('where')->once()->with('sl.organization = :org')->andReturnSelf();
        $qb->shouldReceive('andWhere')->once()->with('sl.isEnabled = :enabled')->andReturnSelf();
        $qb->shouldReceive('setParameter')
            ->andReturnUsing(function (string $key, $value) use (&$enabledCaptured, $qb) {
                if ($key === 'enabled') {
                    $enabledCaptured = $value;
                }

                return $qb;
            });
        $this->expectScalarQueryResult($qb, '3');

        self::assertSame(3, $this->repository->countEnabledByOrganization($this->organization));
        self::assertTrue($enabledCaptured);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function expectQueryBuilder(): QueryBuilder|m\LegacyMockInterface
    {
        $qb = m::mock(QueryBuilder::class);

        // EntityRepository::createQueryBuilder() chains select() -> from().
        $qb->shouldReceive('select')->with('sl')->andReturnSelf()->byDefault();
        $qb->shouldReceive('from')->with(SyncList::class, 'sl', null)->andReturnSelf()->byDefault();

        $this->entityManager->shouldReceive('createQueryBuilder')->andReturn($qb);

        return $qb;
    }

    private function expectQueryResult(m\LegacyMockInterface $qb, array $result): void
    {
        $query = m::mock(Query::class);
        $query->shouldReceive('getResult')->andReturn($result);
        $qb->shouldReceive('getQuery')->andReturn($query);
    }

    private function expectScalarQueryResult(m\LegacyMockInterface $qb, mixed $result): void
    {
        $query = m::mock(Query::class);
        $query->shouldReceive('getSingleScalarResult')->andReturn($result);
        $qb->shouldReceive('getQuery')->andReturn($query);
    }
}
