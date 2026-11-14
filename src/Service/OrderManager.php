<?php

namespace App\Service;

use App\Entity\AuditLog;
use App\Entity\Order;
use App\Entity\OrderItem;
use App\Entity\Product;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;

/**
 * Cancellation (buyer or admin), simulated refund (admin) and the cash-on-delivery outcome (seller) of an order.
 *
 * SECURITY / integrity (rule 10): the status change and the stock restoration happen in ONE transaction, with the
 * order row and the product rows locked (SELECT ... FOR UPDATE), so a double click or two people acting at the same
 * time can't restore the stock twice or collect the cash twice: the second transaction re-reads the order, sees the
 * new state and the state machine (Order::mark...) throws. Permission checks are done by OrderVoter in the
 * controllers.
 */
final class OrderManager
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AuditLogger $audit,
    ) {
    }

    /**
     * @param string $by 'buyer' or 'admin' (for the audit log)
     *
     * @throws \DomainException when the order can't be cancelled any more
     */
    public function cancel(Order $order, string $by): void
    {
        $this->em->wrapInTransaction(function () use ($order): void {
            $this->lock($order);
            $order->markCancelled();
            $this->restoreStock($order);
            $this->em->flush();
        });

        $this->audit->log(AuditLog::ORDER_CANCELLED, [
            'order' => (string) $order->getUuid(), 'total' => $order->getTotal(), 'by' => $by,
            'payment' => $order->getPaymentMethod()->value,
        ]);
    }

    /**
     * @throws \DomainException when the order can't be refunded
     */
    public function refund(Order $order): void
    {
        $this->em->wrapInTransaction(function () use ($order): void {
            $this->lock($order);
            $order->markRefunded();
            $this->restoreStock($order);
            $this->em->flush();
        });

        $this->audit->log(AuditLog::ORDER_REFUNDED, ['order' => (string) $order->getUuid(), 'total' => $order->getTotal(), 'by' => 'admin']);
    }

    /**
     * Cash on delivery: the seller confirms the buyer paid at the door (paymentStatus pending -> collected).
     *
     * @throws \DomainException when the order is not a delivered, unpaid cash order
     */
    public function collectCash(Order $order): void
    {
        $this->em->wrapInTransaction(function () use ($order): void {
            // SECURITY: re-read under a write lock; a second click finds "collected" and the state machine throws.
            $this->lock($order);
            $order->markCashCollected();
            $this->em->flush();
        });

        $this->audit->log(AuditLog::CASH_COLLECTED, ['order' => (string) $order->getUuid(), 'total' => $order->getTotal(), 'by' => 'seller']);
    }

    /**
     * Cash on delivery: the buyer refused the parcel. The order is cancelled and the stock comes back, in one
     * transaction (same locks as cancel()).
     *
     * @throws \DomainException when the order is not a shipped, unpaid cash order
     */
    public function refuseDelivery(Order $order): void
    {
        $this->em->wrapInTransaction(function () use ($order): void {
            $this->lock($order);
            $order->markRefusedAtDelivery();
            $this->restoreStock($order);
            $this->em->flush();
        });

        $this->audit->log(AuditLog::CASH_REFUSED, ['order' => (string) $order->getUuid(), 'total' => $order->getTotal(), 'by' => 'seller']);
    }

    private function lock(Order $order): void
    {
        // Re-read the order under a write lock: a concurrent cancel/refund waits, then sees the new status.
        $this->em->refresh($order, LockMode::PESSIMISTIC_WRITE);
    }

    private function restoreStock(Order $order): void
    {
        $products = array_filter(array_map(static fn (OrderItem $i) => $i->getProduct(), $order->getItems()->toArray()));
        if ([] !== $products) {
            $ids = array_values(array_unique(array_map(static fn (Product $p) => $p->getId(), $products)));
            sort($ids);
            // Products locked in id order (same order as CheckoutService: no deadlock between the two).
            $this->em->createQueryBuilder()->select('p')->from(Product::class, 'p')
                ->where('p.id IN (:ids)')->setParameter('ids', $ids)->orderBy('p.id', 'ASC')
                ->getQuery()->setLockMode(LockMode::PESSIMISTIC_WRITE)
                // Refreshed with the locked values, so the units are added to the CURRENT stock, not to a stale copy.
                ->setHint(Query::HINT_REFRESH, true)->getResult();
        }

        foreach ($order->getItems() as $item) {
            // A product deleted since the order (SET NULL) has no stock to restore.
            $item->getProduct()?->restoreStock($item->getQuantity());
        }
    }
}
