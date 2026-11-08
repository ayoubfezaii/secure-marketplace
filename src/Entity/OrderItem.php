<?php

namespace App\Entity;

use App\Repository\OrderItemRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * One line of an order. Name and unit price are SNAPSHOTS taken inside the checkout transaction: later price
 * changes or product deletion don't alter what the buyer paid (rule 10).
 */
#[ORM\Entity(repositoryClass: OrderItemRepository::class)]
class OrderItem
{
    /** Allowed status transitions for a seller: only forward, one step at a time. */
    public const TRANSITIONS = [
        Order::STATUS_PAID => Order::STATUS_SHIPPED,
        Order::STATUS_SHIPPED => Order::STATUS_DELIVERED,
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $uuid;

    #[ORM\ManyToOne(inversedBy: 'items')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Order $order;

    // SET NULL: deleting a product keeps the order history intact.
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Product $product;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private User $seller;

    #[ORM\Column(length: 120)]
    private string $productName;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2)]
    private string $unitPrice;

    #[ORM\Column]
    private int $quantity;

    #[ORM\Column(length: 16)]
    private string $status = Order::STATUS_PAID;

    public function __construct(Order $order, Product $product, int $quantity)
    {
        $this->uuid = Uuid::v4();
        $this->order = $order;
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

    public function getLineTotal(): string
    {
        return bcmul($this->unitPrice, (string) $this->quantity, 2);
    }

    public function getStatus(): string
    {
        return $this->status;
    }

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
        if ($this->getNextStatus() !== $requested) {
            throw new \DomainException('Invalid status transition.');
        }
        $this->status = $requested;
        $this->order->refreshStatus();
    }
}
