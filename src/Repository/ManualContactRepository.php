<?php

namespace App\Repository;

use App\Entity\ManualContact;
use App\Entity\SyncList;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ManualContact>
 */
class ManualContactRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ManualContact::class);
    }

    /**
     * Returns all ManualContact entities associated with the given SyncList.
     *
     * @return ManualContact[]
     */
    public function findBySyncList(SyncList $syncList): array
    {
        return $this->createQueryBuilder('mc')
            ->innerJoin('mc.syncLists', 'sl')
            ->where('sl = :syncList')
            ->setParameter('syncList', $syncList)
            ->orderBy('mc.email', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
