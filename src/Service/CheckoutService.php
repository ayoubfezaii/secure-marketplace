<?php

namespace App\Service;

use App\Entity\Address;
use App\Entity\AuditLog;
use App\Entity\CartItem;
use App\Entity\Order;
use App\Entity\OrderItem;
use App\Entity\Product;
use App\Entity\User;
use App\Exception\CheckoutException;
use App\Repository\CartItemRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;

/**
 * Places and cancels orders (rule 10: price tampering, and race conditions on stock).
 *
 * placeOrder(): everything happens in ONE database transaction:
 *  1. the products in the cart are locked with SELECT ... FOR UPDATE (pessimistic write lock), in id order to
 *     avoid deadlocks, and re-read from the DB (fresh price, stock, active flag);
 *  2. every line is checked (still active, not the buyer's own product, enough stock);
 *  3. stock is decremented, the Order + OrderItems are created with a price SNAPSHOT from the locked rows, and the
 *     shipping address is copied from the buyer's own saved address;
 *  4. the cart is emptied; commit.
 * Any failure rolls everything back. Two buyers racing for the last unit: the second one waits for the lock,
 * then sees stock 0 and gets an error. Stock can never go negative and no price from the client is ever used.
 */
final class CheckoutService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly CartItemRepository $cartItems,
        private readonly AuditLogger $audit,
    ) {
    }

    /**
     * @param string $cardHolder name on the card (validated by PaymentDto)
     * @param string $cardLast4  last 4 digits only: the full number never reaches this service
     *
     * @throws CheckoutException with a message safe to show to the buyer
     */
    public function placeOrder(User $buyer, Address $address, string $cardHolder, string $cardLast4): Order
    {
        // SECURITY (rule 4): defense in depth, the controller already loaded the address scoped to the buyer.
        if ($address->getUser()->getId() !== $buyer->getId()) {
            throw new CheckoutException('Invalid shipping address.');
        }
        if (1 !== preg_match('/^\d{4}$/', $cardLast4)) {
            throw new CheckoutException('Invalid payment.');
        }

        $order = $this->em->wrapInTransaction(function () use ($buyer, $address, $cardHolder, $cardLast4): Order {
            $lines = $this->cartItems->findForUser($buyer);
            if ([] === $lines) {
                throw new CheckoutException('Your cart is empty.');
            }

            $products = $this->lockProducts(array_map(static fn (CartItem $l) => $l->getProduct()->getId(), $lines));

            $order = new Order($buyer, trim($cardHolder), $cardLast4);
            $order->setShippingAddress($address);

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
                if ($quantity < 1 || $quantity > CartItem::MAX_QUANTITY) {
                    throw new CheckoutException('Invalid quantity in your cart.');
                }
                if ($product->getStock() < $quantity) {
                    throw new CheckoutException(sprintf('Only %d unit(s) of "%s" left in stock. Please update your cart.', $product->getStock(), $product->getName()));
                }

                // Stock decremented on the LOCKED row.
                $product->decreaseStock($quantity);
                // SECURITY (rule 10): unit price copied from the locked DB row.
                $item = new OrderItem($order, $product, $quantity);
                $order->addItem($item);
                $this->em->remove($line);
            }

            $this->em->persist($order);
            $this->em->flush();

            return $order;
        });

        // Logged AFTER the commit, so a rolled-back order never appears as placed.
        $this->audit->log(AuditLog::ORDER_PLACED, [
            'order' => (string) $order->getUuid(),
            'total' => $order->getTotal(),
            'items' => $order->getItems()->count(),
        ], $buyer);

        return $order;
    }

    /**
     * @param list<int|null> $ids
     *
     * @return array<int, Product> indexed by id
     */
    private function lockProducts(array $ids): array
    {
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

        $byId = [];
        foreach ($rows as $product) {
            $byId[(int) $product->getId()] = $product;
        }

        return $byId;
    }
}
