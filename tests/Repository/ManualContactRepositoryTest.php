<?php

namespace App\Tests\Repository;

use App\Entity\ManualContact;
use App\Entity\SyncList;
use App\Repository\ManualContactRepository;
use Doctrine\ORM\AbstractQuery;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Mockery\Adapter\Phpunit\MockeryTestCase;
use Mockery as m;
use Symfony\Component\Uid\Uuid;

class ManualContactRepositoryTest extends MockeryTestCase
{
    private EntityManagerInterface|m\LegacyMockInterface $em;
    private QueryBuilder|m\LegacyMockInterface $queryBuilder;
    private AbstractQuery|m\LegacyMockInterface $query;
    private ManualContactRepository $repository;

    /** @var array<string, mixed> */
    private array $parameters = [];

    protected function setUp(): void
    {
        $registry = m::mock(ManagerRegistry::class);
        $this->em = m::mock(EntityManagerInterface::class);
        $this->queryBuilder = m::mock(QueryBuilder::class);
        $this->query = m::mock(Query::class);

        $classMetadata = new ClassMetadata(ManualContact::class);
        $classMetadata->name = ManualContact::class;

        $registry->shouldReceive('getManagerForClass')
            ->with(ManualContact::class)
            ->andReturn($this->em);

        $this->em->shouldReceive('getClassMetadata')
            ->with(ManualContact::class)
            ->andReturn($classMetadata);

        // EntityRepository::createQueryBuilder calls $em->createQueryBuilder()->select(...)->from(...)
        $this->em->shouldReceive('createQueryBuilder')->andReturn($this->queryBuilder);
        $this->queryBuilder->shouldReceive('select')->andReturnSelf();
        $this->queryBuilder->shouldReceive('from')->andReturnSelf();

        $this->parameters = [];
        $this->queryBuilder->shouldReceive('setParameter')
            ->andReturnUsing(function (string $key, $value) {
                $this->parameters[$key] = $value;

                return $this->queryBuilder;
            });

        $this->repository = new ManualContactRepository($registry);
    }

    public function testFindBySyncListBuildsQueryWithJoinFilterAndOrdering(): void
    {
        $listId = Uuid::v7();
        $syncList = m::mock(SyncList::class);
        $syncList->shouldReceive('getId')->andReturn($listId);

        $contact1 = m::mock(ManualContact::class);
        $contact2 = m::mock(ManualContact::class);

        $this->queryBuilder->shouldReceive('innerJoin')
            ->once()
            ->with('mc.syncLists', 'sl')
            ->andReturnSelf();

        $this->queryBuilder->shouldReceive('where')
            ->once()
            ->with('sl = :syncList')
            ->andReturnSelf();

        $this->queryBuilder->shouldReceive('orderBy')
            ->once()
            ->with('mc.email', 'ASC')
            ->andReturnSelf();

        $this->queryBuilder->shouldReceive('getQuery')->once()->andReturn($this->query);
        $this->query->shouldReceive('getResult')->once()->andReturn([$contact1, $contact2]);

        $result = $this->repository->findBySyncList($syncList);

        $this->assertSame([$contact1, $contact2], $result);
        $this->assertSame(['syncList' => $listId], $this->parameters);
    }

    public function testFindBySyncListReturnsEmptyArrayWhenNoMatches(): void
    {
        $listId = Uuid::v7();
        $syncList = m::mock(SyncList::class);
        $syncList->shouldReceive('getId')->andReturn($listId);

        $this->queryBuilder->shouldReceive('innerJoin')->andReturnSelf();
        $this->queryBuilder->shouldReceive('where')->andReturnSelf();
        $this->queryBuilder->shouldReceive('orderBy')->andReturnSelf();
        $this->queryBuilder->shouldReceive('getQuery')->andReturn($this->query);
        $this->query->shouldReceive('getResult')->andReturn([]);

        $this->assertSame([], $this->repository->findBySyncList($syncList));
        $this->assertSame(['syncList' => $listId], $this->parameters);
    }
}
