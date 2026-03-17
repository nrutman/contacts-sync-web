<?php

namespace App\Repository;

use App\Entity\Organization;
use App\Entity\SyncList;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SyncList>
 */
class SyncListRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SyncList::class);
    }

    /**
     * Returns all sync lists for the given organization, ordered by name.
     *
     * @return SyncList[]
     */
    public function findByOrganization(Organization $organization): array
    {
        return $this->createQueryBuilder('sl')
            ->where('sl.organization = :org')
            ->setParameter('org', $organization->getId())
            ->orderBy('sl.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Returns all enabled sync lists for the given organization.
     *
     * @return SyncList[]
     */
    public function findEnabledByOrganization(Organization $organization): array
    {
        return $this->createQueryBuilder('sl')
            ->where('sl.organization = :org')
            ->andWhere('sl.isEnabled = :enabled')
            ->setParameter('org', $organization->getId())
            ->setParameter('enabled', true)
            ->orderBy('sl.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Returns sync lists matching the given IDs, scoped to the organization.
     *
     * @param string[] $ids
     *
     * @return SyncList[]
     */
    public function findByOrganizationAndIds(Organization $organization, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return $this->createQueryBuilder('sl')
            ->where('sl.organization = :org')
            ->andWhere('sl.id IN (:ids)')
            ->setParameter('org', $organization->getId())
            ->setParameter('ids', $ids)
            ->getQuery()
            ->getResult();
    }

    /**
     * Returns the count of all sync lists for the given organization.
     */
    public function countByOrganization(Organization $organization): int
    {
        return (int) $this->createQueryBuilder('sl')
            ->select('COUNT(sl.id)')
            ->where('sl.organization = :org')
            ->setParameter('org', $organization->getId())
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Returns the count of enabled sync lists for the given organization.
     */
    public function countEnabledByOrganization(Organization $organization): int
    {
        return (int) $this->createQueryBuilder('sl')
            ->select('COUNT(sl.id)')
            ->where('sl.organization = :org')
            ->andWhere('sl.isEnabled = :enabled')
            ->setParameter('org', $organization->getId())
            ->setParameter('enabled', true)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
