<?php

/**
 * FILE: src/Entity/OrderItem.php
 * WHAT: the OrderItem entity = one line of an order (product, quantity, price paid).
 * ROLE IN THE PROJECT: keeps a copy of what was bought and has its own status, because each seller ships
 *   their own lines of an order.
 * USED BY: CheckoutService creates it (buyer); Seller\OrderController moves its status forward (seller);
 *   the order pages of the buyer, the seller and the admin display it.
 */

namespace App\Entity;

use App\Service\Money;
use App\Repository\OrderItemRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * One line of an order. Name and unit price are SNAPSHOTS taken inside the checkout transaction: later price
 * changes or product deletion don't alter what the buyer paid (rule 10).
 */
#[ORM\Entity(repositoryClass: OrderItemRepository::class)]
class OrderItem
{
    /** Allowed status transitions for a seller: only forward, one step at a time. */
    public const TRANSITIONS = [
        // Cash on delivery starts at "placed", card at "paid"; both then follow the same path.
        Order::STATUS_PLACED => Order::STATUS_SHIPPED,
        Order::STATUS_PAID => Order::STATUS_SHIPPED,
        Order::STATUS_SHIPPED => Order::STATUS_DELIVERED,
    ];

    // Internal auto-increment primary key.
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    // Random public identifier, used in URLs and forms instead of the id.
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $uuid;

    // The order this line belongs to.
    #[ORM\ManyToOne(inversedBy: 'items')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Order $order;

    // SET NULL: deleting a product keeps the order history intact.
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Product $product;

    // The seller of the product at the time of purchase (the person who must ship this line).
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private User $seller;

    // Product name copied at checkout (snapshot).
    #[ORM\Column(length: 120)]
    #[Assert\Length(max: 120)]
    private string $productName;

    // SECURITY: price tampering - unit price copied from the database at checkout. It never changes afterwards.
    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 3)]
    #[Assert\Positive]
    private string $unitPrice;

    // Quantity bought (1..99).
    #[ORM\Column]
    #[Assert\Range(min: 1, max: 99)]
    private int $quantity;

    // Status of this line (same list as the order statuses).
    #[ORM\Column(length: 16)]
    #[Assert\Choice(choices: Order::STATUSES)]
    private string $status = Order::STATUS_PAID;

    /**
     * Creates an order line from a product. Called only by CheckoutService inside the transaction.
     *
     * @param Order   $order    the order being created
     * @param Product $product  the product row, locked for the checkout
     * @param int     $quantity quantity bought
     */
    public function __construct(Order $order, Product $product, int $quantity)
    {
        $this->uuid = Uuid::v4();
        $this->order = $order;
        // "paid" for a card order, "placed" for cash on delivery (decided by the order, never by the request).
        $this->status = $order->getInitialStatus();
        $this->product = $product;
        $this->seller = $product->getSeller();
        $this->productName = $product->getName();
        // SECURITY (rule 10): the price comes from the locked product row, never from the request.
        $this->unitPrice = $product->getPrice();
        $this->quantity = $quantity;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUuid(): Uuid
    {
        return $this->uuid;
    }

    public function getOrder(): Order
    {
        return $this->order;
    }

    public function getProduct(): ?Product
    {
        return $this->product;
    }

    public function getSeller(): User
    {
        return $this->seller;
    }

    public function getProductName(): string
    {
        return $this->productName;
    }

    public function getUnitPrice(): string
    {
        return $this->unitPrice;
    }

    public function getQuantity(): int
    {
        return $this->quantity;
    }

    /** Quantity x unit price, computed with exact decimal math (bcmul), not floats. */
    public function getLineTotal(): string
    {
        return bcmul($this->unitPrice, (string) $this->quantity, Money::SCALE);
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    /** Whole-order cancellation / refund (called by Order::markCancelled() / markRefunded() only). */
    public function forceStatus(string $status): void
    {
        if (!in_array($status, [Order::STATUS_CANCELLED, Order::STATUS_REFUNDED], true)) {
            throw new \DomainException('Invalid status.');
        }
        $this->status = $status;
    }

    /** The next status the seller is allowed to set, or null when the line is finished. */
    public function getNextStatus(): ?string
    {
        return self::TRANSITIONS[$this->status] ?? null;
    }

    /**
     * SECURITY: state machine; the only accepted value is the single next step (no "paid" -> "delivered" jump,
     * no going back, no arbitrary string).
     */
    public function advanceStatus(string $requested): void
    {
        // Accept only the exact next step.
        if ($this->getNextStatus() !== $requested) {
            throw new \DomainException('Invalid status transition.');
        }
        $this->status = $requested;
        // The parent order recomputes its own status from all its lines.
        $this->order->refreshStatus();
    }
}
