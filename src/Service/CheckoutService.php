<?php

/**
 * FILE: src/Service/CheckoutService.php
 * WHAT: service that turns a cart into an order.
 * ROLE IN THE PROJECT: the heart of the shop and the main defence against PRICE TAMPERING and stock race
 *   conditions. Read the class comment below: it lists the 5 steps of the transaction.
 * USED BY: CheckoutController::place (buyer).
 */

namespace App\Service;

use App\Entity\Address;
use App\Entity\AuditLog;
use App\Entity\CartItem;
use App\Entity\Order;
use App\Entity\OrderItem;
use App\Entity\Product;
use App\Entity\User;
use App\Enum\PaymentMethod;
use App\Exception\CashOnDeliveryException;
use App\Exception\CheckoutException;
use App\Repository\CartItemRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;

/**
 * Places and cancels orders (rule 10: price tampering, and race conditions on stock).
 *
 * placeOrder(): everything happens in ONE database transaction, identical for card and cash on delivery:
 *  1. the products in the cart are locked with SELECT ... FOR UPDATE (pessimistic write lock), in id order to
 *     avoid deadlocks, and re-read from the DB (fresh price, stock, active flag);
 *  2. every line is checked (still active, not the buyer's own product, enough stock);
 *  3. stock is decremented, the Order + OrderItems are created with a price SNAPSHOT from the locked rows, and the
 *     shipping address is copied from the buyer's own saved address;
 *  4. cash on delivery only: the anti-abuse rules (CashOnDeliveryPolicy) are checked against the total the server
 *     just computed; a refusal rolls the whole transaction back (stock included);
 *  5. the cart is emptied; commit.
 * Any failure rolls everything back. Two buyers racing for the last unit: the second one waits for the lock,
 * then sees stock 0 and gets an error. Stock can never go negative and no price from the client is ever used.
 */
final class CheckoutService
{
    /**
     * @param EntityManagerInterface $em         Doctrine, to run the transaction and the locks
     * @param CartItemRepository     $cartItems  reads the buyer's cart
     * @param AuditLogger            $audit      writes into the audit log
     * @param CashOnDeliveryPolicy   $cashPolicy anti-abuse rules of cash on delivery
     */
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly CartItemRepository $cartItems,
        private readonly AuditLogger $audit,
        private readonly CashOnDeliveryPolicy $cashPolicy,
    ) {
    }

    /**
     * Creates the order of $buyer from the cart stored in the database.
     * SECURITY: price tampering - look at the parameters: there is NO price, NO quantity and NO product list.
     * The browser cannot send them; they are read from the database inside the transaction.
     *
     * @param User          $buyer      the logged-in buyer
     * @param Address       $address    one of the buyer's own addresses
     * @param PaymentMethod $method     enum: the caller can only pass one of the two whitelisted methods
     * @param string|null   $cardHolder card only: name on the card (validated by PaymentDto)
     * @param string|null   $cardLast4  card only: last 4 digits; the full number never reaches this service
     *
     * @return Order the saved order
     *
     * @throws CashOnDeliveryException when an anti-abuse rule blocks a cash order (already audit-logged)
     * @throws CheckoutException       with a message safe to show to the buyer
     */
    public function placeOrder(User $buyer, Address $address, PaymentMethod $method, ?string $cardHolder = null, ?string $cardLast4 = null): Order
    {
        // SECURITY (rule 4): defense in depth, the controller already loaded the address scoped to the buyer.
        if ($address->getUser()->getId() !== $buyer->getId()) {
            throw new CheckoutException('Invalid shipping address.');
        }
        // True for cash on delivery, false for card.
        $cash = PaymentMethod::CashOnDelivery === $method;
        // SECURITY: the card rules depend on the method decided by the SERVER (enum), not on which fields are present.
        if (!$cash && (null === $cardHolder || '' === trim($cardHolder) || null === $cardLast4 || 1 !== preg_match('/^\d{4}$/', $cardLast4))) {
            throw new CheckoutException('Invalid payment.');
        }

        // Server-computed total of a blocked cash order, kept for the audit log.
        $total = null;

        // wrapInTransaction(): BEGIN, run the function, COMMIT. If the function throws, ROLLBACK (nothing is saved).
        try {
            $order = $this->em->wrapInTransaction(function () use ($buyer, $address, $cash, $cardHolder, $cardLast4, &$total): Order {
                if ($cash) {
                    // SECURITY: the buyer row is locked first, so two cash checkouts of the same account run one
                    // after the other: the second one sees the first order when counting the pending cash orders
                    // (no race around the "max 2 pending" rule).
                    $this->em->lock($buyer, LockMode::PESSIMISTIC_WRITE);
                }

                // Read the cart from the database (never from the request).
                $lines = $this->cartItems->findForUser($buyer);
                if ([] === $lines) {
                    throw new CheckoutException('Your cart is empty.');
                }

                // Lock and re-read all the products of the cart (see lockProducts() below).
                $products = $this->lockProducts(array_map(static fn (CartItem $l) => $l->getProduct()->getId(), $lines));

                // SECURITY: a cash order never stores card data, even if some was sent.
                $order = $cash ? new Order($buyer, PaymentMethod::CashOnDelivery) : new Order($buyer, PaymentMethod::Card, trim((string) $cardHolder), $cardLast4);
                $order->setShippingAddress($address);
                $sellerIds = [];

                // Check every cart line against the locked product, then create the order line.
                foreach ($lines as $line) {
                    $product = $products[$line->getProduct()->getId()] ?? null;
                    $quantity = $line->getQuantity();

                    if (null === $product || !$product->isActive()) {
                        throw new CheckoutException(sprintf('"%s" is no longer available. Please remove it from your cart.', $line->getProduct()->getName()));
                    }
                    // SECURITY: you can't buy your own product (defense in depth: buyer and seller are separate roles).
                    if ($product->getSeller()->getId() === $buyer->getId()) {
                        throw new CheckoutException('You cannot buy your own product.');
                    }
                    // SECURITY: validation - quantity re-checked here even if the cart already limits it.
                    if ($quantity < 1 || $quantity > CartItem::MAX_QUANTITY) {
                        throw new CheckoutException('Invalid quantity in your cart.');
                    }
                    // SECURITY: overselling - not enough stock: stop. Checked on the locked row, so the value cannot change now.
                    if ($product->getStock() < $quantity) {
                        throw new CheckoutException(sprintf('Only %d unit(s) of "%s" left in stock. Please update your cart.', $product->getStock(), $product->getName()));
                    }

                    // Stock decremented on the LOCKED row (reserved the same way for card and cash orders).
                    $product->decreaseStock($quantity);
                    // SECURITY (rule 10): unit price copied from the locked DB row.
                    $item = new OrderItem($order, $product, $quantity);
                    $order->addItem($item);
                    $sellerIds[] = (int) $product->getSeller()->getId();
                    // The cart line is deleted (the cart is emptied at the end of the transaction).
                    $this->em->remove($line);
                }

                if ($cash) {
                    // SECURITY: anti-abuse rules on the total computed above from the locked DB prices. Throwing here
                    // rolls back the transaction: no order, stock untouched, cart kept.
                    $total = $order->getTotal();
                    $this->cashPolicy->assertAllowed($buyer, $total, $sellerIds);
                }

                // Save the order, its lines, the new stock and the cart deletion. COMMIT happens after the return.
                $this->em->persist($order);
                $this->em->flush();

                return $order;
            });
        } catch (CashOnDeliveryException $e) {
            // Logged AFTER the rollback (the audit row must survive it), then reported to the controller.
            $this->cashPolicy->logBlocked($buyer, $e, $total);

            throw $e;
        }

        // Logged AFTER the commit, so a rolled-back order never appears as placed.
        $this->audit->log($cash ? AuditLog::CASH_ORDER_PLACED : AuditLog::ORDER_PLACED, [
            'order' => (string) $order->getUuid(),
            'total' => $order->getTotal(),
            'items' => $order->getItems()->count(),
            'payment' => $method->value,
        ], $buyer);

        return $order;
    }

    /**
     * Loads the products with a database lock (SELECT ... FOR UPDATE).
     *
     * @param list<int|null> $ids
     *
     * @return array<int, Product> indexed by id
     */
    private function lockProducts(array $ids): array
    {
        // Remove duplicates and sort the ids: always locking in the same order avoids deadlocks.
        $ids = array_values(array_unique(array_filter($ids)));
        sort($ids);

        /** @var list<Product> $rows */
        $rows = $this->em->createQueryBuilder()
            ->select('p')
            ->from(Product::class, 'p')
            ->where('p.id IN (:ids)')
            ->setParameter('ids', $ids)
            ->orderBy('p.id', 'ASC')
            ->getQuery()
            // SECURITY: SELECT ... FOR UPDATE: other checkouts of the same products wait until we commit.
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            // Products already loaded with the cart are REFRESHED with the locked values (fresh price and stock).
            ->setHint(Query::HINT_REFRESH, true)
            ->getResult();

        // Index the products by id for a fast lookup.
        $byId = [];
        foreach ($rows as $product) {
            $byId[(int) $product->getId()] = $product;
        }

        return $byId;
    }
}
