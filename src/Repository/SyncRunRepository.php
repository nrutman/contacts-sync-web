<?php

namespace App\Repository;

use App\Entity\Organization;
use App\Entity\SyncList;
use App\Entity\SyncRun;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bridge\Doctrine\Types\UuidType;

/**
 * @extends ServiceEntityRepository<SyncRun>
 */
class SyncRunRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SyncRun::class);
    }

    /**
     * Returns the most recent sync runs across all lists for the given organization.
     *
     * @return SyncRun[]
     */
    public function findRecentByOrganization(Organization $organization, int $limit = 10): array
    {
        return $this->createQueryBuilder('sr')
            ->innerJoin('sr.syncList', 'sl')
            ->where('sl.organization = :org')
            ->setParameter('org', $organization->getId(), UuidType::NAME)
            ->orderBy('sr.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Returns the most recent completed sync run for the given organization.
     */
    public function findLastCompletedByOrganization(Organization $organization): ?SyncRun
    {
        return $this->createQueryBuilder('sr')
            ->innerJoin('sr.syncList', 'sl')
            ->where('sl.organization = :org')
            ->andWhere('sr.status IN (:statuses)')
            ->setParameter('org', $organization->getId(), UuidType::NAME)
            ->setParameter('statuses', ['success', 'failed'])
            ->orderBy('sr.completedAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Returns paginated sync runs for a specific sync list.
     *
     * @return SyncRun[]
     */
    public function findBySyncList(SyncList $syncList, int $limit = 50, int $offset = 0): array
    {
        return $this->createQueryBuilder('sr')
            ->where('sr.syncList = :syncList')
            ->setParameter('syncList', $syncList->getId(), UuidType::NAME)
            ->orderBy('sr.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->setFirstResult($offset)
            ->getQuery()
            ->getResult();
    }

    /**
     * Returns paginated sync runs across all lists for the given organization,
     * with optional filtering by list and status.
     *
     * @return SyncRun[]
     */
    public function findByOrganizationPaginated(
        Organization $organization,
        int $limit = 25,
        int $offset = 0,
        ?SyncList $syncList = null,
        ?string $status = null,
    ): array {
        $qb = $this->createQueryBuilder('sr')
            ->innerJoin('sr.syncList', 'sl')
            ->where('sl.organization = :org')
            ->setParameter('org', $organization->getId(), UuidType::NAME);

        if ($syncList !== null) {
            $qb->andWhere('sr.syncList = :syncList')
                ->setParameter('syncList', $syncList->getId(), UuidType::NAME);
        }

        if ($status !== null) {
            $qb->andWhere('sr.status = :status')
                ->setParameter('status', $status);
        }

        return $qb->orderBy('sr.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->setFirstResult($offset)
            ->getQuery()
            ->getResult();
    }

    /**
     * Returns the most recent sync run for the given list, regardless of status.
     */
    public function findLastBySyncList(SyncList $syncList): ?SyncRun
    {
        return $this->createQueryBuilder('sr')
            ->where('sr.syncList = :syncList')
            ->setParameter('syncList', $syncList->getId(), UuidType::NAME)
            ->orderBy('sr.createdAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Returns a map of sync list ID → destination contact count from the last successful sync run for each list.
     *
     * @return array<string, int>
     */
    public function findDestinationCountsByOrganization(Organization $organization): array
    {
        $conn = $this->getEntityManager()->getConnection();

        $sql = <<<'SQL'
            SELECT sr.sync_list_id AS list_id, sr.destination_count
            FROM sync_run sr
            INNER JOIN sync_list sl ON sr.sync_list_id = sl.id
            INNER JOIN (
                SELECT sync_list_id, MAX(completed_at) AS max_completed
                FROM sync_run
                WHERE status = 'success'
                GROUP BY sync_list_id
            ) latest ON sr.sync_list_id = latest.sync_list_id AND sr.completed_at = latest.max_completed
            WHERE sl.organization_id = :org
              AND sr.status = 'success'
              AND sr.destination_count IS NOT NULL
            SQL;

        $results = $conn->fetchAllAssociative($sql, [
            'org' => (string) $organization->getId(),
        ]);

        $counts = [];
        foreach ($results as $row) {
            $counts[$row['list_id']] = (int) $row['destination_count'];
        }

        return $counts;
    }

    /**
     * Returns the total count of sync runs for the given organization with optional filters.
     */
    public function countByOrganization(
        Organization $organization,
        ?SyncList $syncList = null,
        ?string $status = null,
    ): int {
        $qb = $this->createQueryBuilder('sr')
            ->select('COUNT(sr.id)')
            ->innerJoin('sr.syncList', 'sl')
            ->where('sl.organization = :org')
            ->setParameter('org', $organization->getId(), UuidType::NAME);

        if ($syncList !== null) {
            $qb->andWhere('sr.syncList = :syncList')
                ->setParameter('syncList', $syncList->getId(), UuidType::NAME);
        }

        if ($status !== null) {
            $qb->andWhere('sr.status = :status')
                ->setParameter('status', $status);
        }

        return (int) $qb->getQuery()->getSingleScalarResult();
    }
}
