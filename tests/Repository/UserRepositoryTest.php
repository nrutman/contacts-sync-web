<?php

namespace App\Tests\Repository;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Mockery as m;
use Mockery\Adapter\Phpunit\MockeryTestCase;

class UserRepositoryTest extends MockeryTestCase
{
    private EntityManagerInterface|m\LegacyMockInterface $entityManager;
    private QueryBuilder|m\LegacyMockInterface $queryBuilder;
    private Query|m\LegacyMockInterface $query;
    private UserRepository $repository;

    protected function setUp(): void
    {
        $this->entityManager = m::mock(EntityManagerInterface::class);
        $this->queryBuilder = m::mock(QueryBuilder::class);
        $this->query = m::mock(Query::class);

        $classMetadata = new ClassMetadata(User::class);

        $this->entityManager
            ->shouldReceive('getClassMetadata')
            ->with(User::class)
            ->andReturn($classMetadata)
            ->byDefault();

        $registry = m::mock(ManagerRegistry::class);
        $registry
            ->shouldReceive('getManagerForClass')
            ->with(User::class)
            ->andReturn($this->entityManager);

        $this->repository = new UserRepository($registry);
    }

    public function testFindAllOrderedSortsByLastNameThenFirstName(): void
    {
        $alice = $this->makeUser('Alice', 'Anderson');
        $bob = $this->makeUser('Bob', 'Brown');

        // EntityRepository::createQueryBuilder() calls em->createQueryBuilder()
        // and chains select() / from(). We mock the resulting QueryBuilder
        // directly so we can assert orderBy / addOrderBy are applied correctly.
        $this->entityManager
            ->shouldReceive('createQueryBuilder')
            ->andReturn($this->queryBuilder);

        $this->queryBuilder->shouldReceive('select')->with('u')->andReturnSelf();
        $this->queryBuilder->shouldReceive('from')
            ->with(User::class, 'u', null)
            ->andReturnSelf();

        $this->queryBuilder
            ->shouldReceive('orderBy')
            ->once()
            ->with('u.lastName', 'ASC')
            ->andReturnSelf();
        $this->queryBuilder
            ->shouldReceive('addOrderBy')
            ->once()
            ->with('u.firstName', 'ASC')
            ->andReturnSelf();

        $this->queryBuilder->shouldReceive('getQuery')->andReturn($this->query);
        $this->query
            ->shouldReceive('getResult')
            ->andReturn([$alice, $bob]);

        $result = $this->repository->findAllOrdered();

        self::assertSame([$alice, $bob], $result);
    }

    public function testFindAllOrderedReturnsEmptyArrayWhenNoUsers(): void
    {
        $this->entityManager
            ->shouldReceive('createQueryBuilder')
            ->andReturn($this->queryBuilder);

        $this->queryBuilder->shouldReceive('select')->andReturnSelf();
        $this->queryBuilder->shouldReceive('from')->andReturnSelf();
        $this->queryBuilder->shouldReceive('orderBy')->andReturnSelf();
        $this->queryBuilder->shouldReceive('addOrderBy')->andReturnSelf();
        $this->queryBuilder->shouldReceive('getQuery')->andReturn($this->query);
        $this->query->shouldReceive('getResult')->andReturn([]);

        $result = $this->repository->findAllOrdered();

        self::assertSame([], $result);
    }

    private function makeUser(string $first, string $last): User
    {
        $user = new User();
        $user->setEmail(strtolower($first).'@example.com');
        $user->setFirstName($first);
        $user->setLastName($last);

        return $user;
    }
}
