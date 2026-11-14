<?php

namespace App\Repository;

use App\Entity\Order;
use App\Entity\User;
use App\Enum\PaymentMethod;
use App\Enum\PaymentStatus;
use App\Service\Money;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * SECURITY (rule 1): QueryBuilder/DQL with bound parameters only; filter values are whitelisted before use.
 *
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
     *
     * @param list<string> $itemStatuses empty = no filter; values outside the whitelist are dropped
     */
    public function createSellerQueryBuilder(User $seller, array $itemStatuses = []): QueryBuilder
    {
        // SECURITY (rule 15): whitelist.
        $itemStatuses = array_values(array_intersect($itemStatuses, Order::STATUSES));
        $sub = 'SELECT IDENTITY(i.order) FROM App\Entity\OrderItem i WHERE i.seller = :seller';
        if ([] !== $itemStatuses) {
            $sub .= ' AND i.status IN (:istatuses)';
        }
        $qb = $this->createQueryBuilder('o')
            ->andWhere('o.id IN ('.$sub.')')->setParameter('seller', $seller)
            ->orderBy('o.createdAt', 'DESC');
        if ([] !== $itemStatuses) {
            $qb->setParameter('istatuses', $itemStatuses);
        }

        return $qb;
    }

    /**
     * Admin list. Every filter is an already-validated value (status whitelist, enums) bound as a parameter.
     */
    public function createAdminQueryBuilder(?string $status, ?PaymentMethod $paymentMethod = null, ?PaymentStatus $paymentStatus = null): QueryBuilder
    {
        $qb = $this->createQueryBuilder('o')
            ->innerJoin('o.buyer', 'b')->addSelect('b')
            ->orderBy('o.createdAt', 'DESC');

        if (null !== $status && in_array($status, Order::STATUSES, true)) {
            $qb->andWhere('o.status = :status')->setParameter('status', $status);
        }
        if (null !== $paymentMethod) {
            $qb->andWhere('o.paymentMethod = :pmethod')->setParameter('pmethod', $paymentMethod->value);
        }
        if (null !== $paymentStatus) {
            $qb->andWhere('o.paymentStatus = :pstatus')->setParameter('pstatus', $paymentStatus->value);
        }

        return $qb;
    }

    /**
     * Cash-on-delivery orders of $buyer that are still running and not paid (anti-abuse limit).
     * Cancelled / refused orders don't count: they no longer reserve stock.
     */
    public function countPendingCashOrders(User $buyer): int
    {
        return (int) $this->pendingCashQueryBuilder()
            ->select('COUNT(o.id)')
            ->andWhere('o.buyer = :buyer')->setParameter('buyer', $buyer)
            ->getQuery()->getSingleScalarResult();
    }

    /**
     * Admin "Cash to collect": number and total of the pending cash orders.
     *
     * @return array{count: int, total: string}
     */
    public function pendingCashTotals(): array
    {
        $row = $this->pendingCashQueryBuilder()
            ->select('COUNT(o.id) AS cnt, COALESCE(SUM(o.total), 0) AS total')
            ->getQuery()->getSingleResult();

        return ['count' => (int) $row['cnt'], 'total' => Money::decimal($row['total'])];
    }

    private function pendingCashQueryBuilder(): QueryBuilder
    {
        return $this->createQueryBuilder('o')
            ->andWhere('o.paymentMethod = :cash')->setParameter('cash', PaymentMethod::CashOnDelivery->value)
            ->andWhere('o.paymentStatus = :pending')->setParameter('pending', PaymentStatus::Pending->value)
            ->andWhere('o.status IN (:active)')->setParameter('active', Order::ACTIVE_STATUSES);
    }
}
