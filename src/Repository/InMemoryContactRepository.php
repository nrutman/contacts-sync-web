<?php

namespace App\Repository;

use App\Entity\InMemoryContact;
use App\Entity\SyncList;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<InMemoryContact>
 */
class InMemoryContactRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, InMemoryContact::class);
    }

    /**
     * Returns all InMemoryContact entities associated with the given SyncList.
     *
     * @return InMemoryContact[]
     */
    public function findBySyncList(SyncList $syncList): array
    {
        return $this->createQueryBuilder('imc')
            ->innerJoin('imc.syncLists', 'sl')
            ->where('sl = :syncList')
            ->setParameter('syncList', $syncList)
            ->orderBy('imc.email', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
