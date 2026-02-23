<?php

namespace App\Repository;

use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<User>
 */
class UserRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, User::class);
    }

    /**
     * Returns all users ordered by last name, first name.
     *
     * @return User[]
     */
    public function findAllOrdered(): array
    {
        return $this->createQueryBuilder('u')
            ->orderBy('u.lastName', 'ASC')
            ->addOrderBy('u.firstName', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Returns all users who have opted in to notifications for the given result type.
     *
     * @param 'success'|'failure'|'no_changes' $resultType
     *
     * @return User[]
     */
    public function findByNotificationPreference(string $resultType): array
    {
        $field = match ($resultType) {
            'success' => 'u.notifyOnSuccess',
            'failure' => 'u.notifyOnFailure',
            'no_changes' => 'u.notifyOnNoChanges',
            default => throw new \InvalidArgumentException(sprintf('Unknown result type "%s".', $resultType)),
        };

        return $this->createQueryBuilder('u')
            ->where($field.' = :enabled')
            ->andWhere('u.isVerified = :verified')
            ->setParameter('enabled', true)
            ->setParameter('verified', true)
            ->getQuery()
            ->getResult();
    }
}
