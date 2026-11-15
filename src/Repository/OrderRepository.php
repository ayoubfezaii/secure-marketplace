<?php

/**
 * FILE: src/Repository/OrderRepository.php
 * WHAT: all the database queries about orders.
 * ROLE IN THE PROJECT: builds the order lists of each role, always limited to what that role may see.
 * USED BY: OrderController (buyer), Seller\OrderController (seller), Admin\ModerationController (admin),
 *   CashOnDeliveryPolicy and the dashboards.
 */

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
    /** Tells Doctrine that this repository works on the Order entity. */
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Order::class);
    }

    /**
     * The orders of one buyer, newest first.
     * SECURITY: IDOR - filtered on the buyer, so a buyer only gets their own orders.
     *
     * @param User     $buyer the logged-in buyer
     * @param int|null $limit maximum number of orders (null = all)
     *
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
     *
     * @param User        $buyer  the logged-in buyer
     * @param string|null $status optional status filter coming from the URL
     *
     * @return QueryBuilder the query, not executed yet (the caller adds the pagination)
     * Buyer "My orders" list (scoped to the buyer, status from the whitelist).
     */
    public function createBuyerQueryBuilder(User $buyer, ?string $status): QueryBuilder
    {
        $qb = $this->createQueryBuilder('o')
            ->andWhere('o.buyer = :buyer')->setParameter('buyer', $buyer)
            ->orderBy('o.createdAt', 'DESC');
        // SECURITY: validation - the status filter is used only if it is a known status.
        if (null !== $status && in_array($status, Order::STATUSES, true)) {
            $qb->andWhere('o.status = :status')->setParameter('status', $status);
        }

        return $qb;
    }

    /**
     * SECURITY: IDOR - the sub-query keeps only orders where this seller sold something.
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
        // Sub-query (written by us, no user text inside): ids of the orders that contain an item of this seller.
        $sub = 'SELECT IDENTITY(i.order) FROM App\Entity\OrderItem i WHERE i.seller = :seller';
        if ([] !== $itemStatuses) {
            $sub .= ' AND i.status IN (:istatuses)';
        }
        // SECURITY: SQL injection - the seller and the statuses are bound parameters (:seller, :istatuses).
        $qb = $this->createQueryBuilder('o')
            ->andWhere('o.id IN ('.$sub.')')->setParameter('seller', $seller)
            ->orderBy('o.createdAt', 'DESC');
        if ([] !== $itemStatuses) {
            $qb->setParameter('istatuses', $itemStatuses);
        }

        return $qb;
    }

    /**
     *
     * @return QueryBuilder the query, not executed yet (the caller adds the pagination)
     * Admin list. Every filter is an already-validated value (status whitelist, enums) bound as a parameter.
     */
    public function createAdminQueryBuilder(?string $status, ?PaymentMethod $paymentMethod = null, ?PaymentStatus $paymentStatus = null): QueryBuilder
    {
        // All orders with their buyer (join = one query instead of one per row), newest first.
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
        // One query that returns both the number of orders and the sum of their totals.
        $row = $this->pendingCashQueryBuilder()
            ->select('COUNT(o.id) AS cnt, COALESCE(SUM(o.total), 0) AS total')
            ->getQuery()->getSingleResult();

        // Money::decimal() formats the sum as a clean decimal string with 3 decimals.
        return ['count' => (int) $row['cnt'], 'total' => Money::decimal($row['total'])];
    }

    /** Common base of the two methods above: cash orders, payment still pending, order still running. */
    private function pendingCashQueryBuilder(): QueryBuilder
    {
        return $this->createQueryBuilder('o')
            ->andWhere('o.paymentMethod = :cash')->setParameter('cash', PaymentMethod::CashOnDelivery->value)
            ->andWhere('o.paymentStatus = :pending')->setParameter('pending', PaymentStatus::Pending->value)
            ->andWhere('o.status IN (:active)')->setParameter('active', Order::ACTIVE_STATUSES);
    }
}
