<?php

/**
 * FILE: src/Repository/AuditLogRepository.php
 * WHAT: all the database queries that READ the audit log (the writes are done by AuditLogger).
 * ROLE IN THE PROJECT: feeds the admin audit viewer, the admin security panel and the user's login history.
 * USED BY: Admin\AuditController (admin), AdminStats (admin dashboard), SecuritySettingsController (any logged-in user).
 */

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
    /** Tells Doctrine that this repository works on the AuditLog entity. */
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AuditLog::class);
    }

    /**
     * Filtered list for the admin viewer. All filters are bound parameters (rule 1).
     *
     * @param string|null             $action    exact event name, or null for all
     * @param string|null             $userEmail part of an email to search for
     * @param \DateTimeImmutable|null $from      first day included
     * @param \DateTimeImmutable|null $to        last day included
     * @param string                  $ip        beginning of an IP address
     *
     * @return QueryBuilder the query, not executed yet (the caller adds the pagination)
     */
    public function createFilteredQueryBuilder(?string $action, ?string $userEmail, ?\DateTimeImmutable $from, ?\DateTimeImmutable $to, string $ip = ''): QueryBuilder
    {
        // Base query: all log lines with their user (one query thanks to the join), newest first.
        $qb = $this->createQueryBuilder('a')
            ->leftJoin('a.user', 'u')->addSelect('u')
            ->orderBy('a.createdAt', 'DESC');

        // Each filter below is added only when the admin filled it.
        if (null !== $action && '' !== $action) {
            $qb->andWhere('a.action = :action')->setParameter('action', $action);
        }
        if (null !== $userEmail && '' !== $userEmail) {
            // SECURITY: the user's text goes into a bound parameter; LIKE wildcards are escaped too.
            $qb->andWhere('u.email LIKE :email')->setParameter('email', '%'.addcslashes($userEmail, '%_\\').'%');
        }
        if ('' !== $ip) {
            // Prefix match on the (format-checked) IP, bound as a parameter.
            $qb->andWhere('a.ip LIKE :ip')->setParameter('ip', addcslashes($ip, '%_\\').'%');
        }
        if (null !== $from) {
            $qb->andWhere('a.createdAt >= :from')->setParameter('from', $from);
        }
        if (null !== $to) {
            // "to" is a day: add one day and use "<" so the whole last day is included.
            $qb->andWhere('a.createdAt < :to')->setParameter('to', $to->modify('+1 day'));
        }

        return $qb;
    }

    /**
     * The newest log lines whose action is in $actions (used for the "security events" list).
     * SECURITY: SQL injection - the list is passed as a bound parameter (IN (:actions)).
     *
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
     * SECURITY: IDOR - filtered on the given user, so nobody sees the history of another account.
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

    /**
     * The newest log lines of any kind (admin dashboard "recent activity").
     *
     * @return list<AuditLog>
     */
    public function findRecent(int $limit = 10): array
    {
        return $this->createQueryBuilder('a')->leftJoin('a.user', 'u')->addSelect('u')
            ->orderBy('a.createdAt', 'DESC')->setMaxResults($limit)->getQuery()->getResult();
    }

    /**
     * How many times $action happened since $since (for example failed logins in the last 24 hours).
     */
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
     * The list of action names that exist in the log (fills the filter dropdown of the audit page).
     *
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
