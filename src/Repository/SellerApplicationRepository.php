<?php

namespace App\Repository;

use App\Entity\SellerApplication;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SellerApplication>
 */
class SellerApplicationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SellerApplication::class);
    }

    /** The applicant's most recent application (to show its status). */
    public function findLatestFor(User $user): ?SellerApplication
    {
        return $this->createQueryBuilder('a')
            ->andWhere('a.applicant = :u')->setParameter('u', $user)
            ->orderBy('a.createdAt', 'DESC')->setMaxResults(1)
            ->getQuery()->getOneOrNullResult();
    }

    public function hasPending(User $user): bool
    {
        return (int) $this->createQueryBuilder('a')->select('COUNT(a.id)')
            ->andWhere('a.applicant = :u')->andWhere('a.status = :s')
            ->setParameter('u', $user)->setParameter('s', SellerApplication::STATUS_PENDING)
            ->getQuery()->getSingleScalarResult() > 0;
    }

    public function countPending(): int
    {
        return (int) $this->createQueryBuilder('a')->select('COUNT(a.id)')
            ->andWhere('a.status = :s')->setParameter('s', SellerApplication::STATUS_PENDING)
            ->getQuery()->getSingleScalarResult();
    }

    /**
     * Admin list: pending first, then newest. Status comes from a whitelist (checked by the caller).
     */
    public function createAdminQueryBuilder(?string $status, string $q = ''): QueryBuilder
    {
        $qb = $this->createQueryBuilder('a')
            ->innerJoin('a.applicant', 'u')->addSelect('u')
            ->addSelect("CASE WHEN a.status = 'pending' THEN 0 ELSE 1 END AS HIDDEN pendingFirst")
            ->orderBy('pendingFirst', 'ASC')->addOrderBy('a.createdAt', 'DESC');
        if (null !== $status && in_array($status, SellerApplication::STATUSES, true)) {
            $qb->andWhere('a.status = :status')->setParameter('status', $status);
        }
        if ('' !== $q) {
            $qb->andWhere('(a.shopName LIKE :q OR u.email LIKE :q)')->setParameter('q', '%'.addcslashes($q, '%_\\').'%');
        }

        return $qb;
    }
}
