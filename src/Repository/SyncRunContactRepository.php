<?php

namespace App\Repository;

use App\Entity\SyncList;
use App\Entity\SyncRunContact;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bridge\Doctrine\Types\UuidType;

/**
 * @extends ServiceEntityRepository<SyncRunContact>
 */
class SyncRunContactRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SyncRunContact::class);
    }

    /**
     * Returns contacts from the most recent successful sync run for the given list.
     *
     * @return SyncRunContact[]
     */
    public function findByLatestSuccessfulRun(SyncList $syncList): array
    {
        $latestRunId = $this->getEntityManager()
            ->createQueryBuilder()
            ->select('sr.id')
            ->from('App\Entity\SyncRun', 'sr')
            ->where('sr.syncList = :syncList')
            ->andWhere('sr.status = :status')
            ->orderBy('sr.completedAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->setParameter('syncList', $syncList->getId(), UuidType::NAME)
            ->setParameter('status', 'success')
            ->getOneOrNullResult();

        if ($latestRunId === null) {
            return [];
        }

        return $this->createQueryBuilder('src')
            ->where('src.syncRun = :runId')
            ->setParameter('runId', $latestRunId['id'], UuidType::NAME)
            ->orderBy('src.name', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
