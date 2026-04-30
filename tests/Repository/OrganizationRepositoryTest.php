<?php

namespace App\Tests\Repository;

use App\Entity\Organization;
use App\Repository\OrganizationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\UnitOfWork;
use Doctrine\Persistence\ManagerRegistry;
use Mockery\Adapter\Phpunit\MockeryTestCase;
use Mockery as m;

class OrganizationRepositoryTest extends MockeryTestCase
{
    private EntityManagerInterface|m\LegacyMockInterface $em;
    private UnitOfWork|m\LegacyMockInterface $unitOfWork;
    private OrganizationRepository $repository;

    protected function setUp(): void
    {
        $registry = m::mock(ManagerRegistry::class);
        $this->em = m::mock(EntityManagerInterface::class);
        $this->unitOfWork = m::mock(UnitOfWork::class);

        $classMetadata = new ClassMetadata(Organization::class);
        $classMetadata->name = Organization::class;

        $registry->shouldReceive('getManagerForClass')
            ->with(Organization::class)
            ->andReturn($this->em);

        $this->em->shouldReceive('getClassMetadata')
            ->with(Organization::class)
            ->andReturn($classMetadata);

        $this->em->shouldReceive('getUnitOfWork')->andReturn($this->unitOfWork);

        $this->repository = new OrganizationRepository($registry);
    }

    public function testFindOneReturnsTheSingleOrganization(): void
    {
        $organization = new Organization();

        $persister = m::mock(\Doctrine\ORM\Persisters\Entity\EntityPersister::class);
        $persister->shouldReceive('load')
            ->with([], null, null, [], null, 1, null)
            ->andReturn($organization);

        $this->unitOfWork->shouldReceive('getEntityPersister')
            ->with(Organization::class)
            ->andReturn($persister);

        $this->assertSame($organization, $this->repository->findOne());
    }

    public function testFindOneReturnsNullWhenNoOrganizationExists(): void
    {
        $persister = m::mock(\Doctrine\ORM\Persisters\Entity\EntityPersister::class);
        $persister->shouldReceive('load')
            ->with([], null, null, [], null, 1, null)
            ->andReturnNull();

        $this->unitOfWork->shouldReceive('getEntityPersister')
            ->with(Organization::class)
            ->andReturn($persister);

        $this->assertNull($this->repository->findOne());
    }
}
