<?php

namespace App\Repository;

use App\Entity\AuditLog;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AuditLog>
 */
class AuditLogRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AuditLog::class);
    }

    /**
     * Filtered list for the admin viewer. All filters are bound parameters (rule 1).
     */
    public function createFilteredQueryBuilder(?string $action, ?string $userEmail, ?\DateTimeImmutable $from, ?\DateTimeImmutable $to): QueryBuilder
    {
        $qb = $this->createQueryBuilder('a')
            ->leftJoin('a.user', 'u')->addSelect('u')
            ->orderBy('a.createdAt', 'DESC');

        if (null !== $action && '' !== $action) {
            $qb->andWhere('a.action = :action')->setParameter('action', $action);
        }
        if (null !== $userEmail && '' !== $userEmail) {
            // SECURITY: the user's text goes into a bound parameter; LIKE wildcards are escaped too.
            $qb->andWhere('u.email LIKE :email')->setParameter('email', '%'.addcslashes($userEmail, '%_\\').'%');
        }
        if (null !== $from) {
            $qb->andWhere('a.createdAt >= :from')->setParameter('from', $from);
        }
        if (null !== $to) {
            $qb->andWhere('a.createdAt < :to')->setParameter('to', $to->modify('+1 day'));
        }

        return $qb;
    }

    /**
     * @param list<string> $actions
     *
     * @return list<AuditLog>
     */
    public function findRecentByActions(array $actions, int $limit = 20): array
    {
        return $this->createQueryBuilder('a')
            ->leftJoin('a.user', 'u')->addSelect('u')
            ->andWhere('a.action IN (:actions)')
            ->setParameter('actions', $actions)
            ->orderBy('a.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * The user's own login history (security page): successes, failures, blocked attempts, 2FA failures, lockouts.
     *
     * @return list<AuditLog>
     */
    public function findLoginHistory(User $user, int $limit = 20): array
    {
        return $this->createQueryBuilder('a')
            ->andWhere('a.user = :u')->andWhere('a.action IN (:actions)')
            ->setParameter('u', $user)
            ->setParameter('actions', [AuditLog::LOGIN_SUCCESS, AuditLog::LOGIN_FAILURE, AuditLog::LOGIN_BLOCKED, AuditLog::TWO_FA_FAILURE, AuditLog::ACCOUNT_LOCKED])
            ->orderBy('a.createdAt', 'DESC')->setMaxResults($limit)
            ->getQuery()->getResult();
    }

    public function countSince(string $action, \DateTimeImmutable $since): int
    {
        return (int) $this->createQueryBuilder('a')
            ->select('COUNT(a.id)')
            ->andWhere('a.action = :action')
            ->andWhere('a.createdAt >= :since')
            ->setParameter('action', $action)
            ->setParameter('since', $since)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * @return list<string>
     */
    public function findDistinctActions(): array
    {
        return array_column($this->createQueryBuilder('a')
            ->select('DISTINCT a.action')
            ->orderBy('a.action', 'ASC')
            ->getQuery()
            ->getScalarResult(), 'action');
    }
}
