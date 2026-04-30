<?php

namespace App\Tests\Repository;

use App\Entity\Organization;
use App\Entity\ProviderCredential;
use App\Repository\ProviderCredentialRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Persisters\Entity\EntityPersister;
use Doctrine\ORM\UnitOfWork;
use Doctrine\Persistence\ManagerRegistry;
use Mockery\Adapter\Phpunit\MockeryTestCase;
use Mockery as m;

class ProviderCredentialRepositoryTest extends MockeryTestCase
{
    private EntityPersister|m\LegacyMockInterface $persister;
    private ProviderCredentialRepository $repository;

    protected function setUp(): void
    {
        $registry = m::mock(ManagerRegistry::class);
        $em = m::mock(EntityManagerInterface::class);
        $unitOfWork = m::mock(UnitOfWork::class);
        $this->persister = m::mock(EntityPersister::class);

        $classMetadata = new ClassMetadata(ProviderCredential::class);
        $classMetadata->name = ProviderCredential::class;

        $registry->shouldReceive('getManagerForClass')
            ->with(ProviderCredential::class)
            ->andReturn($em);

        $em->shouldReceive('getClassMetadata')
            ->with(ProviderCredential::class)
            ->andReturn($classMetadata);

        $em->shouldReceive('getUnitOfWork')->andReturn($unitOfWork);
        $unitOfWork->shouldReceive('getEntityPersister')
            ->with(ProviderCredential::class)
            ->andReturn($this->persister);

        $this->repository = new ProviderCredentialRepository($registry);
    }

    public function testFindByOrganizationFiltersByOrgAndOrdersByProviderThenCreatedAt(): void
    {
        $organization = new Organization();
        $expected = [m::mock(ProviderCredential::class), m::mock(ProviderCredential::class)];

        $this->persister->shouldReceive('loadAll')
            ->once()
            ->with(
                ['organization' => $organization],
                ['providerName' => 'ASC', 'createdAt' => 'ASC'],
                null,
                null,
            )
            ->andReturn($expected);

        $this->assertSame($expected, $this->repository->findByOrganization($organization));
    }

    public function testFindByOrganizationReturnsEmptyArrayWhenNoCredentials(): void
    {
        $organization = new Organization();

        $this->persister->shouldReceive('loadAll')
            ->once()
            ->andReturn([]);

        $this->assertSame([], $this->repository->findByOrganization($organization));
    }

    public function testFindByOrganizationAndProviderFiltersByBothAndOrdersByCreatedAt(): void
    {
        $organization = new Organization();
        $expected = [m::mock(ProviderCredential::class)];

        $this->persister->shouldReceive('loadAll')
            ->once()
            ->with(
                ['organization' => $organization, 'providerName' => 'google'],
                ['createdAt' => 'ASC'],
                null,
                null,
            )
            ->andReturn($expected);

        $this->assertSame(
            $expected,
            $this->repository->findByOrganizationAndProvider($organization, 'google'),
        );
    }

    public function testFindByOrganizationAndProviderReturnsEmptyArrayWhenNoMatches(): void
    {
        $organization = new Organization();

        $this->persister->shouldReceive('loadAll')
            ->once()
            ->with(
                ['organization' => $organization, 'providerName' => 'planning_center'],
                ['createdAt' => 'ASC'],
                null,
                null,
            )
            ->andReturn([]);

        $this->assertSame(
            [],
            $this->repository->findByOrganizationAndProvider($organization, 'planning_center'),
        );
    }
}
