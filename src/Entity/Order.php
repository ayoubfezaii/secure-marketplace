<?php

namespace App\Entity;

use App\Repository\OrderRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * A placed order. Created ONLY by CheckoutService inside a DB transaction; nothing here comes from the
 * request except the card holder name and the last 4 digits of the (fake) card.
 */
#[ORM\Entity(repositoryClass: OrderRepository::class)]
#[ORM\Table(name: 'orders')]
#[ORM\Index(name: 'idx_order_created', columns: ['created_at'])]
class Order
{
    public const STATUS_PAID = 'paid';
    public const STATUS_SHIPPED = 'shipped';
    public const STATUS_DELIVERED = 'delivered';
    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [self::STATUS_PAID, self::STATUS_SHIPPED, self::STATUS_DELIVERED, self::STATUS_CANCELLED];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    // SECURITY: random public id; order numbers can't be guessed or enumerated (rule 4).
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $uuid;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private User $buyer;

    // SECURITY: computed server side from DB prices (rule 10).
    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 2)]
    private string $total = '0.00';

    #[ORM\Column(length: 16)]
    private string $status = self::STATUS_PAID;

    #[ORM\Column(length: 100)]
    private string $cardHolder;

    // SECURITY: only the last 4 digits are stored. Never the full number, never the CVV (PCI-DSS principle).
    #[ORM\Column(length: 4)]
    private string $cardLast4;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    /** @var Collection<int, OrderItem> */
    #[ORM\OneToMany(targetEntity: OrderItem::class, mappedBy: 'order', cascade: ['persist'])]
    private Collection $items;

    public function __construct(User $buyer, string $cardHolder, string $cardLast4)
    {
        $this->uuid = Uuid::v4();
        $this->buyer = $buyer;
        $this->cardHolder = $cardHolder;
        $this->cardLast4 = $cardLast4;
        $this->createdAt = new \DateTimeImmutable();
        $this->items = new ArrayCollection();
    }

    public function addItem(OrderItem $item): void
    {
        $this->items->add($item);
        $this->total = bcadd($this->total, $item->getLineTotal(), 2);
    }

    /**
     * The order status follows its items: delivered when all are delivered, shipped when all are at least shipped.
     */
    public function refreshStatus(): void
    {
        $statuses = array_unique(array_map(static fn (OrderItem $i) => $i->getStatus(), $this->items->toArray()));
        $this->status = match (true) {
            [] === $statuses => self::STATUS_PAID,
            [self::STATUS_CANCELLED] === array_values($statuses) => self::STATUS_CANCELLED,
            [] === array_diff($statuses, [self::STATUS_DELIVERED, self::STATUS_CANCELLED]) => self::STATUS_DELIVERED,
            [] === array_diff($statuses, [self::STATUS_SHIPPED, self::STATUS_DELIVERED, self::STATUS_CANCELLED]) => self::STATUS_SHIPPED,
            default => self::STATUS_PAID,
        };
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUuid(): Uuid
    {
        return $this->uuid;
    }

    public function getBuyer(): User
    {
        return $this->buyer;
    }

    public function getTotal(): string
    {
        return $this->total;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getCardHolder(): string
    {
        return $this->cardHolder;
    }

    public function getCardLast4(): string
    {
        return $this->cardLast4;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /** @return Collection<int, OrderItem> */
    public function getItems(): Collection
    {
        return $this->items;
    }

    /**
     * Items belonging to one seller (a seller must only see their own lines of a shared order).
     *
     * @return list<OrderItem>
     */
    public function getItemsForSeller(User $seller): array
    {
        return array_values(array_filter($this->items->toArray(), static fn (OrderItem $i) => $i->getSeller()->getId() === $seller->getId()));
    }

    public function hasSeller(User $seller): bool
    {
        return [] !== $this->getItemsForSeller($seller);
    }
}
