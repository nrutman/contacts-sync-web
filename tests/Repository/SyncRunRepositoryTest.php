<?php

namespace App\Tests\Repository;

use App\Entity\Organization;
use App\Entity\SyncList;
use App\Entity\SyncRun;
use App\Repository\SyncRunRepository;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Mockery\Adapter\Phpunit\MockeryTestCase;
use Mockery as m;
use Symfony\Component\Uid\Uuid;

class SyncRunRepositoryTest extends MockeryTestCase
{
    private EntityManagerInterface|m\LegacyMockInterface $em;
    private Connection|m\LegacyMockInterface $connection;
    private SyncRunRepository $repository;

    /** @var QueryBuilder[] */
    private array $queryBuilderQueue = [];

    protected function setUp(): void
    {
        $registry = m::mock(ManagerRegistry::class);
        $this->em = m::mock(EntityManagerInterface::class);
        $this->connection = m::mock(Connection::class);

        $classMetadata = new ClassMetadata(SyncRun::class);
        $classMetadata->name = SyncRun::class;

        $registry->shouldReceive('getManagerForClass')
            ->with(SyncRun::class)
            ->andReturn($this->em);

        $this->em->shouldReceive('getClassMetadata')
            ->with(SyncRun::class)
            ->andReturn($classMetadata);

        $this->em->shouldReceive('getConnection')->andReturn($this->connection);

        // Hand out queued QB mocks one at a time.
        $this->em->shouldReceive('createQueryBuilder')
            ->andReturnUsing(function () {
                if ($this->queryBuilderQueue === []) {
                    throw new \RuntimeException('Unexpected createQueryBuilder() call');
                }

                return array_shift($this->queryBuilderQueue);
            });

        $this->repository = new SyncRunRepository($registry);
    }

    /**
     * Returns a chainable QueryBuilder mock that records every setParameter() call.
     * Builder methods are recorded as no-op chainable defaults via byDefault(); add
     * explicit expectations on top to assert specific arguments or call counts.
     *
     * @param array<string, mixed> $paramSink reference to a captured-parameters array
     */
    private function makeChainableQb(array &$paramSink): QueryBuilder|m\LegacyMockInterface
    {
        $qb = m::mock(QueryBuilder::class);
        // EntityRepository::createQueryBuilder calls these on the EM-returned QB.
        $qb->shouldReceive('select')->andReturnSelf()->byDefault();
        $qb->shouldReceive('from')->andReturnSelf()->byDefault();
        // Builder methods commonly used; tests can override per-method to assert args.
        $qb->shouldReceive('innerJoin')->andReturnSelf()->byDefault();
        $qb->shouldReceive('where')->andReturnSelf()->byDefault();
        $qb->shouldReceive('andWhere')->andReturnSelf()->byDefault();
        $qb->shouldReceive('orderBy')->andReturnSelf()->byDefault();
        $qb->shouldReceive('setMaxResults')->andReturnSelf()->byDefault();
        $qb->shouldReceive('setFirstResult')->andReturnSelf()->byDefault();
        $qb->shouldReceive('setParameter')
            ->andReturnUsing(function (string $key, $value) use ($qb, &$paramSink) {
                $paramSink[$key] = $value;

                return $qb;
            });

        return $qb;
    }

    public function testFindRecentByOrganizationFiltersByOrgAndAppliesLimit(): void
    {
        $orgId = Uuid::v7();
        $organization = m::mock(Organization::class);
        $organization->shouldReceive('getId')->andReturn($orgId);

        $params = [];
        $qb = $this->makeChainableQb($params);
        $this->queryBuilderQueue[] = $qb;

        $expected = [m::mock(SyncRun::class)];
        $query = m::mock(Query::class);
        $qb->shouldReceive('getQuery')->andReturn($query);
        $query->shouldReceive('getResult')->andReturn($expected);

        $qb->shouldReceive('innerJoin')->with('sr.syncList', 'sl')->andReturnSelf();
        $qb->shouldReceive('where')->with('sl.organization = :org')->andReturnSelf();
        $qb->shouldReceive('orderBy')->with('sr.createdAt', 'DESC')->andReturnSelf();
        $qb->shouldReceive('setMaxResults')->with(5)->andReturnSelf();

        $result = $this->repository->findRecentByOrganization($organization, 5);

        $this->assertSame($expected, $result);
        $this->assertSame(['org' => $orgId], $params);
    }

    public function testFindRecentByOrganizationDefaultsLimitToTen(): void
    {
        $organization = m::mock(Organization::class);
        $organization->shouldReceive('getId')->andReturn(Uuid::v7());

        $params = [];
        $qb = $this->makeChainableQb($params);
        $this->queryBuilderQueue[] = $qb;

        $query = m::mock(Query::class);
        $qb->shouldReceive('getQuery')->andReturn($query);
        $query->shouldReceive('getResult')->andReturn([]);

        $qb->shouldReceive('setMaxResults')->once()->with(10)->andReturnSelf();

        $this->repository->findRecentByOrganization($organization);
    }

    public function testFindLastCompletedByOrganizationFiltersByOrgAndStatusList(): void
    {
        $orgId = Uuid::v7();
        $organization = m::mock(Organization::class);
        $organization->shouldReceive('getId')->andReturn($orgId);

        $params = [];
        $qb = $this->makeChainableQb($params);
        $this->queryBuilderQueue[] = $qb;

        $expected = m::mock(SyncRun::class);
        $query = m::mock(Query::class);
        $qb->shouldReceive('getQuery')->andReturn($query);
        $query->shouldReceive('getOneOrNullResult')->andReturn($expected);

        $qb->shouldReceive('innerJoin')->with('sr.syncList', 'sl')->andReturnSelf();
        $qb->shouldReceive('where')->with('sl.organization = :org')->andReturnSelf();
        $qb->shouldReceive('andWhere')->with('sr.status IN (:statuses)')->andReturnSelf();
        $qb->shouldReceive('orderBy')->with('sr.completedAt', 'DESC')->andReturnSelf();
        $qb->shouldReceive('setMaxResults')->with(1)->andReturnSelf();

        $result = $this->repository->findLastCompletedByOrganization($organization);

        $this->assertSame($expected, $result);
        $this->assertSame($orgId, $params['org']);
        $this->assertSame(['success', 'failed'], $params['statuses']);
    }

    public function testFindLastCompletedByOrganizationReturnsNullWhenNoneFound(): void
    {
        $organization = m::mock(Organization::class);
        $organization->shouldReceive('getId')->andReturn(Uuid::v7());

        $params = [];
        $qb = $this->makeChainableQb($params);
        $this->queryBuilderQueue[] = $qb;

        $query = m::mock(Query::class);
        $qb->shouldReceive('getQuery')->andReturn($query);
        $query->shouldReceive('getOneOrNullResult')->andReturnNull();

        $this->assertNull($this->repository->findLastCompletedByOrganization($organization));
    }

    public function testFindBySyncListFiltersByListAndAppliesPagination(): void
    {
        $listId = Uuid::v7();
        $syncList = m::mock(SyncList::class);
        $syncList->shouldReceive('getId')->andReturn($listId);

        $params = [];
        $qb = $this->makeChainableQb($params);
        $this->queryBuilderQueue[] = $qb;

        $expected = [m::mock(SyncRun::class)];
        $query = m::mock(Query::class);
        $qb->shouldReceive('getQuery')->andReturn($query);
        $query->shouldReceive('getResult')->andReturn($expected);

        $qb->shouldReceive('where')->with('sr.syncList = :syncList')->andReturnSelf();
        $qb->shouldReceive('orderBy')->with('sr.createdAt', 'DESC')->andReturnSelf();
        $qb->shouldReceive('setMaxResults')->with(20)->andReturnSelf();
        $qb->shouldReceive('setFirstResult')->with(40)->andReturnSelf();

        $result = $this->repository->findBySyncList($syncList, 20, 40);

        $this->assertSame($expected, $result);
        $this->assertSame(['syncList' => $listId], $params);
    }

    public function testFindBySyncListUsesDefaultPagination(): void
    {
        $syncList = m::mock(SyncList::class);
        $syncList->shouldReceive('getId')->andReturn(Uuid::v7());

        $params = [];
        $qb = $this->makeChainableQb($params);
        $this->queryBuilderQueue[] = $qb;

        $query = m::mock(Query::class);
        $qb->shouldReceive('getQuery')->andReturn($query);
        $query->shouldReceive('getResult')->andReturn([]);

        $qb->shouldReceive('setMaxResults')->once()->with(50)->andReturnSelf();
        $qb->shouldReceive('setFirstResult')->once()->with(0)->andReturnSelf();

        $this->repository->findBySyncList($syncList);
    }

    public function testFindByOrganizationPaginatedAppliesOnlyOrgFilterByDefault(): void
    {
        $orgId = Uuid::v7();
        $organization = m::mock(Organization::class);
        $organization->shouldReceive('getId')->andReturn($orgId);

        $params = [];
        $qb = $this->makeChainableQb($params);
        $this->queryBuilderQueue[] = $qb;

        $expected = [m::mock(SyncRun::class)];
        $query = m::mock(Query::class);
        $qb->shouldReceive('getQuery')->andReturn($query);
        $query->shouldReceive('getResult')->andReturn($expected);

        $qb->shouldReceive('innerJoin')->with('sr.syncList', 'sl')->andReturnSelf();
        $qb->shouldReceive('where')->with('sl.organization = :org')->andReturnSelf();
        // No optional andWhere clauses should fire when filters are absent.
        $qb->shouldNotReceive('andWhere');

        $result = $this->repository->findByOrganizationPaginated($organization);

        $this->assertSame($expected, $result);
        $this->assertSame(['org' => $orgId], $params);
    }

    public function testFindByOrganizationPaginatedAppliesAllOptionalFilters(): void
    {
        $orgId = Uuid::v7();
        $listId = Uuid::v7();
        $organization = m::mock(Organization::class);
        $organization->shouldReceive('getId')->andReturn($orgId);
        $syncList = m::mock(SyncList::class);
        $syncList->shouldReceive('getId')->andReturn($listId);

        $params = [];
        $qb = $this->makeChainableQb($params);
        $this->queryBuilderQueue[] = $qb;

        $query = m::mock(Query::class);
        $qb->shouldReceive('getQuery')->andReturn($query);
        $query->shouldReceive('getResult')->andReturn([]);

        $andWhereClauses = [];
        $qb->shouldReceive('andWhere')
            ->andReturnUsing(function (string $clause) use ($qb, &$andWhereClauses) {
                $andWhereClauses[] = $clause;

                return $qb;
            });

        $this->repository->findByOrganizationPaginated(
            $organization,
            10,
            5,
            $syncList,
            'success',
            true,
        );

        $this->assertSame($orgId, $params['org']);
        $this->assertSame($listId, $params['syncList']);
        $this->assertSame('success', $params['status']);
        $this->assertContains('sr.syncList = :syncList', $andWhereClauses);
        $this->assertContains('sr.status = :status', $andWhereClauses);
        $this->assertContains('sr.addedCount > 0 OR sr.removedCount > 0', $andWhereClauses);
    }

    public function testFindLastBySyncListReturnsMostRecentRun(): void
    {
        $listId = Uuid::v7();
        $syncList = m::mock(SyncList::class);
        $syncList->shouldReceive('getId')->andReturn($listId);

        $params = [];
        $qb = $this->makeChainableQb($params);
        $this->queryBuilderQueue[] = $qb;

        $run = m::mock(SyncRun::class);
        $query = m::mock(Query::class);
        $qb->shouldReceive('getQuery')->andReturn($query);
        $query->shouldReceive('getOneOrNullResult')->andReturn($run);

        $qb->shouldReceive('where')->with('sr.syncList = :syncList')->andReturnSelf();
        $qb->shouldReceive('orderBy')->with('sr.createdAt', 'DESC')->andReturnSelf();
        $qb->shouldReceive('setMaxResults')->with(1)->andReturnSelf();

        $this->assertSame($run, $this->repository->findLastBySyncList($syncList));
        $this->assertSame(['syncList' => $listId], $params);
    }

    public function testFindLastBySyncListReturnsNullWhenNoRunsExist(): void
    {
        $syncList = m::mock(SyncList::class);
        $syncList->shouldReceive('getId')->andReturn(Uuid::v7());

        $params = [];
        $qb = $this->makeChainableQb($params);
        $this->queryBuilderQueue[] = $qb;

        $query = m::mock(Query::class);
        $qb->shouldReceive('getQuery')->andReturn($query);
        $query->shouldReceive('getOneOrNullResult')->andReturnNull();

        $this->assertNull($this->repository->findLastBySyncList($syncList));
    }

    public function testFindDestinationCountsByOrganizationReturnsKeyedMap(): void
    {
        $orgId = Uuid::v7();
        $organization = m::mock(Organization::class);
        $organization->shouldReceive('getId')->andReturn($orgId);

        $listIdA = (string) Uuid::v7();
        $listIdB = (string) Uuid::v7();

        $this->connection->shouldReceive('fetchAllAssociative')
            ->once()
            ->with(
                m::on(fn (string $sql) => str_contains($sql, 'sl.organization_id = :org')
                    && str_contains($sql, "sr.status = 'success'")
                    && str_contains($sql, 'sr.destination_count IS NOT NULL')),
                ['org' => $orgId],
            )
            ->andReturn([
                ['list_id' => $listIdA, 'destination_count' => '17'],
                ['list_id' => $listIdB, 'destination_count' => '0'],
            ]);

        $result = $this->repository->findDestinationCountsByOrganization($organization);

        $this->assertSame([$listIdA => 17, $listIdB => 0], $result);
    }

    public function testFindDestinationCountsByOrganizationReturnsEmptyMapWhenNoRows(): void
    {
        $organization = m::mock(Organization::class);
        $organization->shouldReceive('getId')->andReturn(Uuid::v7());

        $this->connection->shouldReceive('fetchAllAssociative')->andReturn([]);

        $this->assertSame([], $this->repository->findDestinationCountsByOrganization($organization));
    }

    public function testFindSourceCountsByOrganizationReturnsKeyedMap(): void
    {
        $orgId = Uuid::v7();
        $organization = m::mock(Organization::class);
        $organization->shouldReceive('getId')->andReturn($orgId);

        $listId = (string) Uuid::v7();

        $this->connection->shouldReceive('fetchAllAssociative')
            ->once()
            ->with(
                m::on(fn (string $sql) => str_contains($sql, 'sl.organization_id = :org')
                    && str_contains($sql, "sr.status = 'success'")
                    && str_contains($sql, 'sync_run_contact')),
                ['org' => $orgId],
            )
            ->andReturn([
                ['list_id' => $listId, 'source_count' => '42'],
            ]);

        $this->assertSame(
            [$listId => 42],
            $this->repository->findSourceCountsByOrganization($organization),
        );
    }

    public function testDeleteOlderThanScopesByOrgAndCutoffAndReturnsCount(): void
    {
        $orgId = Uuid::v7();
        $organization = m::mock(Organization::class);
        $organization->shouldReceive('getId')->andReturn($orgId);

        $cutoff = new \DateTimeImmutable('2025-01-01T00:00:00Z');
        $expectedParams = ['org' => $orgId, 'cutoff' => $cutoff];
        $expectedTypes = ['cutoff' => 'datetime_immutable'];

        // First call: deletes SyncRunContacts. Second call: deletes SyncRuns and returns the count.
        $this->connection->shouldReceive('executeStatement')
            ->once()
            ->with(
                m::on(fn (string $sql) => str_starts_with($sql, 'DELETE src FROM sync_run_contact src')
                    && str_contains($sql, 'sl.organization_id = :org')
                    && str_contains($sql, 'sr.completed_at < :cutoff')
                    && str_contains($sql, "sr.status IN ('success', 'failed')")
                    && str_contains($sql, 'sr.id NOT IN')),
                $expectedParams,
                $expectedTypes,
            )
            ->andReturn(99);

        $this->connection->shouldReceive('executeStatement')
            ->once()
            ->with(
                m::on(fn (string $sql) => str_starts_with($sql, 'DELETE sr FROM sync_run sr')
                    && str_contains($sql, 'sl.organization_id = :org')
                    && str_contains($sql, 'sr.completed_at < :cutoff')
                    && str_contains($sql, "sr.status IN ('success', 'failed')")
                    && str_contains($sql, 'sr.id NOT IN')),
                $expectedParams,
                $expectedTypes,
            )
            ->andReturn(7);

        $this->assertSame(7, $this->repository->deleteOlderThan($organization, $cutoff));
    }

    public function testCountByOrganizationCountsWithOrgFilterOnly(): void
    {
        $orgId = Uuid::v7();
        $organization = m::mock(Organization::class);
        $organization->shouldReceive('getId')->andReturn($orgId);

        $params = [];
        $qb = $this->makeChainableQb($params);
        $this->queryBuilderQueue[] = $qb;

        $query = m::mock(Query::class);
        $qb->shouldReceive('getQuery')->andReturn($query);
        $query->shouldReceive('getSingleScalarResult')->andReturn('123');

        $qb->shouldReceive('select')->with('COUNT(sr.id)')->andReturnSelf();
        $qb->shouldReceive('innerJoin')->with('sr.syncList', 'sl')->andReturnSelf();
        $qb->shouldReceive('where')->with('sl.organization = :org')->andReturnSelf();
        $qb->shouldNotReceive('andWhere');

        $this->assertSame(123, $this->repository->countByOrganization($organization));
        $this->assertSame(['org' => $orgId], $params);
    }

    public function testCountByOrganizationAppliesAllOptionalFilters(): void
    {
        $orgId = Uuid::v7();
        $listId = Uuid::v7();
        $organization = m::mock(Organization::class);
        $organization->shouldReceive('getId')->andReturn($orgId);
        $syncList = m::mock(SyncList::class);
        $syncList->shouldReceive('getId')->andReturn($listId);

        $params = [];
        $qb = $this->makeChainableQb($params);
        $this->queryBuilderQueue[] = $qb;

        $query = m::mock(Query::class);
        $qb->shouldReceive('getQuery')->andReturn($query);
        $query->shouldReceive('getSingleScalarResult')->andReturn('5');

        $andWhereClauses = [];
        $qb->shouldReceive('andWhere')
            ->andReturnUsing(function (string $clause) use ($qb, &$andWhereClauses) {
                $andWhereClauses[] = $clause;

                return $qb;
            });

        $count = $this->repository->countByOrganization($organization, $syncList, 'failed', true);

        $this->assertSame(5, $count);
        $this->assertSame($orgId, $params['org']);
        $this->assertSame($listId, $params['syncList']);
        $this->assertSame('failed', $params['status']);
        $this->assertContains('sr.syncList = :syncList', $andWhereClauses);
        $this->assertContains('sr.status = :status', $andWhereClauses);
        $this->assertContains('sr.addedCount > 0 OR sr.removedCount > 0', $andWhereClauses);
    }
}
