<?php

namespace App\Tests\Repository;

use App\Entity\SyncList;
use App\Entity\SyncRunContact;
use App\Repository\SyncRunContactRepository;
use Doctrine\ORM\AbstractQuery;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Mockery\Adapter\Phpunit\MockeryTestCase;
use Mockery as m;
use Symfony\Component\Uid\Uuid;

class SyncRunContactRepositoryTest extends MockeryTestCase
{
    private EntityManagerInterface|m\LegacyMockInterface $em;
    private QueryBuilder|m\LegacyMockInterface $latestRunQb;
    private QueryBuilder|m\LegacyMockInterface $contactsQb;
    private AbstractQuery|m\LegacyMockInterface $latestRunQuery;
    private AbstractQuery|m\LegacyMockInterface $contactsQuery;
    private SyncRunContactRepository $repository;

    /** @var array<string, mixed> */
    private array $latestRunParams = [];

    /** @var array<string, mixed> */
    private array $contactsParams = [];

    protected function setUp(): void
    {
        $registry = m::mock(ManagerRegistry::class);
        $this->em = m::mock(EntityManagerInterface::class);

        $classMetadata = new ClassMetadata(SyncRunContact::class);
        $classMetadata->name = SyncRunContact::class;

        $registry->shouldReceive('getManagerForClass')
            ->with(SyncRunContact::class)
            ->andReturn($this->em);

        $this->em->shouldReceive('getClassMetadata')
            ->with(SyncRunContact::class)
            ->andReturn($classMetadata);

        // The method creates two query builders:
        //  1. EM->createQueryBuilder() with no select/from chain pre-applied (latest run lookup)
        //  2. $this->createQueryBuilder('src') for SyncRunContacts (the repo's helper -> EM->createQueryBuilder()->select->from)
        // We return them in that order.
        $this->latestRunQb = m::mock(QueryBuilder::class);
        $this->contactsQb = m::mock(QueryBuilder::class);
        $this->latestRunQuery = m::mock(Query::class);
        $this->contactsQuery = m::mock(Query::class);

        $this->em->shouldReceive('createQueryBuilder')
            ->andReturn($this->latestRunQb, $this->contactsQb);

        // Latest run QB: select->from->where->andWhere->orderBy->setMaxResults
        $this->latestRunQb->shouldReceive('select')->with('sr.id')->andReturnSelf();
        $this->latestRunQb->shouldReceive('from')->with('App\Entity\SyncRun', 'sr')->andReturnSelf();
        $this->latestRunQb->shouldReceive('where')->with('sr.syncList = :syncList')->andReturnSelf();
        $this->latestRunQb->shouldReceive('andWhere')->with('sr.status = :status')->andReturnSelf();
        $this->latestRunQb->shouldReceive('orderBy')->with('sr.completedAt', 'DESC')->andReturnSelf();
        $this->latestRunQb->shouldReceive('setMaxResults')->with(1)->andReturnSelf();
        $this->latestRunQb->shouldReceive('getQuery')->andReturn($this->latestRunQuery);
        $this->latestRunQuery->shouldReceive('setParameter')
            ->andReturnUsing(function (string $key, $value) {
                $this->latestRunParams[$key] = $value;

                return $this->latestRunQuery;
            });

        // Contacts QB: select(alias) and from() are called by EntityRepository::createQueryBuilder
        $this->contactsQb->shouldReceive('select')->with('src')->andReturnSelf();
        $this->contactsQb->shouldReceive('from')
            ->with(SyncRunContact::class, 'src', null)
            ->andReturnSelf();
        $this->contactsQb->shouldReceive('where')->with('src.syncRun = :runId')->andReturnSelf();
        $this->contactsQb->shouldReceive('orderBy')->with('src.name', 'ASC')->andReturnSelf();
        $this->contactsQb->shouldReceive('setParameter')
            ->andReturnUsing(function (string $key, $value) {
                $this->contactsParams[$key] = $value;

                return $this->contactsQb;
            });
        $this->contactsQb->shouldReceive('getQuery')->andReturn($this->contactsQuery);

        $this->repository = new SyncRunContactRepository($registry);
    }

    public function testFindByLatestSuccessfulRunReturnsContactsWhenRunExists(): void
    {
        $listId = Uuid::v7();
        $runId = Uuid::v7();
        $syncList = m::mock(SyncList::class);
        $syncList->shouldReceive('getId')->andReturn($listId);

        $this->latestRunQuery->shouldReceive('getOneOrNullResult')
            ->andReturn(['id' => $runId]);

        $contact1 = m::mock(SyncRunContact::class);
        $contact2 = m::mock(SyncRunContact::class);
        $this->contactsQuery->shouldReceive('getResult')
            ->andReturn([$contact1, $contact2]);

        $result = $this->repository->findByLatestSuccessfulRun($syncList);

        $this->assertSame([$contact1, $contact2], $result);
        $this->assertSame(
            ['syncList' => $listId, 'status' => 'success'],
            $this->latestRunParams,
        );
        $this->assertSame(['runId' => $runId], $this->contactsParams);
    }

    public function testFindByLatestSuccessfulRunReturnsEmptyWhenNoSuccessfulRun(): void
    {
        $listId = Uuid::v7();
        $syncList = m::mock(SyncList::class);
        $syncList->shouldReceive('getId')->andReturn($listId);

        $this->latestRunQuery->shouldReceive('getOneOrNullResult')->andReturnNull();
        // Contacts query should NOT be invoked at all when no run is found.
        $this->contactsQuery->shouldNotReceive('getResult');

        $result = $this->repository->findByLatestSuccessfulRun($syncList);

        $this->assertSame([], $result);
        $this->assertSame(
            ['syncList' => $listId, 'status' => 'success'],
            $this->latestRunParams,
        );
    }

    public function testFindByLatestSuccessfulRunOnlyConsidersSuccessfulRuns(): void
    {
        // Verifies the status param is exactly 'success' (not e.g. 'completed' or 'failed').
        $syncList = m::mock(SyncList::class);
        $syncList->shouldReceive('getId')->andReturn(Uuid::v7());

        $this->latestRunQuery->shouldReceive('getOneOrNullResult')->andReturnNull();

        $this->repository->findByLatestSuccessfulRun($syncList);

        $this->assertSame('success', $this->latestRunParams['status']);
    }
}
