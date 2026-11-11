<?php

namespace App\Repository;

use App\Entity\Order;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Order>
 */
class OrderRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Order::class);
    }

    /**
     * @return list<Order>
     */
    public function findForBuyer(User $buyer, ?int $limit = null): array
    {
        return $this->createQueryBuilder('o')
            ->andWhere('o.buyer = :buyer')
            ->setParameter('buyer', $buyer)
            ->orderBy('o.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Buyer "My orders" list (scoped to the buyer, status from the whitelist).
     */
    public function createBuyerQueryBuilder(User $buyer, ?string $status): QueryBuilder
    {
        $qb = $this->createQueryBuilder('o')
            ->andWhere('o.buyer = :buyer')->setParameter('buyer', $buyer)
            ->orderBy('o.createdAt', 'DESC');
        if (null !== $status && in_array($status, Order::STATUSES, true)) {
            $qb->andWhere('o.status = :status')->setParameter('status', $status);
        }

        return $qb;
    }

    /**
     * Orders that contain at least one item sold by $seller.
     *
     * @return list<Order>
     */
    public function findForSeller(User $seller, ?int $limit = null): array
    {
        return $this->createQueryBuilder('o')
            ->andWhere('o.id IN (SELECT IDENTITY(i.order) FROM App\Entity\OrderItem i WHERE i.seller = :seller)')
            ->setParameter('seller', $seller)
            ->orderBy('o.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Seller list: orders containing at least one of the seller's items; optional filter on the status of THOSE items.
     */
    public function createSellerQueryBuilder(User $seller, ?string $itemStatus): QueryBuilder
    {
        $sub = 'SELECT IDENTITY(i.order) FROM App\Entity\OrderItem i WHERE i.seller = :seller';
        if (null !== $itemStatus && in_array($itemStatus, Order::STATUSES, true)) {
            $sub .= ' AND i.status = :istatus';
        }
        $qb = $this->createQueryBuilder('o')
            ->andWhere('o.id IN ('.$sub.')')->setParameter('seller', $seller)
            ->orderBy('o.createdAt', 'DESC');
        if (null !== $itemStatus && in_array($itemStatus, Order::STATUSES, true)) {
            $qb->setParameter('istatus', $itemStatus);
        }

        return $qb;
    }

    /**
     * Admin list. The status filter is checked against the whitelist before use.
     */
    public function createAdminQueryBuilder(?string $status): QueryBuilder
    {
        $qb = $this->createQueryBuilder('o')
            ->innerJoin('o.buyer', 'b')->addSelect('b')
            ->orderBy('o.createdAt', 'DESC');

        if (null !== $status && in_array($status, Order::STATUSES, true)) {
            $qb->andWhere('o.status = :status')->setParameter('status', $status);
        }

        return $qb;
    }
}
