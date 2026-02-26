<?php

namespace App\Repository;

use App\Entity\Organization;
use App\Entity\ProviderCredential;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ProviderCredential>
 */
class ProviderCredentialRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ProviderCredential::class);
    }

    /**
     * @return ProviderCredential[]
     */
    public function findByOrganization(Organization $organization): array
    {
        return $this->findBy(
            ['organization' => $organization],
            ['providerName' => 'ASC', 'createdAt' => 'ASC'],
        );
    }

    /**
     * @return ProviderCredential[]
     */
    public function findByOrganizationAndProvider(Organization $organization, string $providerName): array
    {
        return $this->findBy(
            ['organization' => $organization, 'providerName' => $providerName],
            ['createdAt' => 'ASC'],
        );
    }
}
