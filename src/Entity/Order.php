<?php

namespace App\Entity;

use App\Service\Money;
use App\Enum\PaymentMethod;
use App\Enum\PaymentStatus;
use App\Repository\OrderRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A placed order. Created ONLY by CheckoutService inside a DB transaction; nothing here comes from the
 * request except the payment method (whitelisted enum), the card holder name and the last 4 digits of the (fake) card.
 *
 * Two payment flows:
 *  - card:             status paid   -> shipped -> delivered, paymentStatus = paid from the start;
 *  - cash on delivery: status placed -> shipped -> delivered, paymentStatus = pending until the seller confirms the
 *                      cash at delivery (collected). A refused delivery cancels the order.
 */
#[ORM\Entity(repositoryClass: OrderRepository::class)]
#[ORM\Table(name: 'orders')]
#[ORM\Index(name: 'idx_order_created', columns: ['created_at'])]
class Order
{
    /** Cash on delivery: order accepted, nothing paid yet. */
    public const STATUS_PLACED = 'placed';
    public const STATUS_PAID = 'paid';
    public const STATUS_SHIPPED = 'shipped';
    public const STATUS_DELIVERED = 'delivered';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_REFUNDED = 'refunded';

    public const STATUSES = [self::STATUS_PLACED, self::STATUS_PAID, self::STATUS_SHIPPED, self::STATUS_DELIVERED, self::STATUS_CANCELLED, self::STATUS_REFUNDED];

    /**
     * Statuses that count as a real sale (revenue, best sellers, "has purchased"). "placed" is NOT in the list, and
     * revenue queries also require paymentStatus paid/collected: an unpaid cash order is never revenue.
     */
    public const SOLD_STATUSES = [self::STATUS_PAID, self::STATUS_SHIPPED, self::STATUS_DELIVERED];

    /** Item statuses waiting for the seller to ship (card: paid, cash: placed). */
    public const AWAITING_SHIPMENT = [self::STATUS_PAID, self::STATUS_PLACED];

    /** Statuses of an order that is still running or done (not cancelled / refunded). */
    public const ACTIVE_STATUSES = [self::STATUS_PLACED, self::STATUS_PAID, self::STATUS_SHIPPED, self::STATUS_DELIVERED];

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
    // Dinars with 3 decimals (millimes).
    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 3)]
    #[Assert\PositiveOrZero]
    private string $total = Money::ZERO;

    #[ORM\Column(length: 16)]
    // SECURITY (rule 15): whitelist of statuses.
    #[Assert\Choice(choices: self::STATUSES)]
    private string $status = self::STATUS_PAID;

    // SECURITY (rules 9, 15): PHP enum mapped by Doctrine; an unknown value can't be stored or loaded.
    #[ORM\Column(length: 20, enumType: PaymentMethod::class)]
    private PaymentMethod $paymentMethod;

    // SECURITY: changed only by the state machine below (never by a form): paid | pending | collected | refunded.
    #[ORM\Column(length: 20, enumType: PaymentStatus::class)]
    private PaymentStatus $paymentStatus;

    // Card orders only (null for cash on delivery).
    #[ORM\Column(length: 100, nullable: true)]
    #[Assert\Length(max: 100)]
    private ?string $cardHolder = null;

    // SECURITY: only the last 4 digits are stored. Never the full number, never the CVV (PCI-DSS principle).
    #[ORM\Column(length: 4, nullable: true)]
    #[Assert\Regex(pattern: '/^\d{4}$/')]
    private ?string $cardLast4 = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    // Shipping address SNAPSHOT copied from the buyer's saved Address at checkout: later edits or deletion of the
    // address don't change where a past order was sent. Sellers see these fields (name + address only), never the
    // buyer's email, phone or payment data.
    #[ORM\Column(length: 100, nullable: true)]
    private ?string $shippingName = null;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $shippingLine1 = null;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $shippingLine2 = null;

    #[ORM\Column(length: 12, nullable: true)]
    private ?string $shippingPostalCode = null;

    #[ORM\Column(length: 80, nullable: true)]
    private ?string $shippingCity = null;

    #[ORM\Column(length: 2, nullable: true)]
    private ?string $shippingCountry = null;

    /** @var Collection<int, OrderItem> */
    #[ORM\OneToMany(targetEntity: OrderItem::class, mappedBy: 'order', cascade: ['persist'])]
    private Collection $items;

    public function __construct(User $buyer, PaymentMethod $paymentMethod, ?string $cardHolder = null, ?string $cardLast4 = null)
    {
        $this->uuid = Uuid::v4();
        $this->buyer = $buyer;
        $this->paymentMethod = $paymentMethod;
        if (PaymentMethod::Card === $paymentMethod) {
            // SECURITY (rule 15): a card order always carries a holder and exactly 4 digits.
            if (null === $cardHolder || '' === trim($cardHolder) || null === $cardLast4 || 1 !== preg_match('/^\d{4}$/', $cardLast4)) {
                throw new \InvalidArgumentException('A card order needs a card holder and the last 4 digits.');
            }
            $this->cardHolder = $cardHolder;
            $this->cardLast4 = $cardLast4;
            $this->paymentStatus = PaymentStatus::Paid;
            $this->status = self::STATUS_PAID;
        } else {
            // SECURITY: cash on delivery never stores card data, and starts unpaid whatever the caller passes.
            $this->paymentStatus = PaymentStatus::Pending;
            $this->status = self::STATUS_PLACED;
        }
        $this->createdAt = new \DateTimeImmutable();
        $this->items = new ArrayCollection();
    }

    public function addItem(OrderItem $item): void
    {
        $this->items->add($item);
        $this->total = bcadd($this->total, $item->getLineTotal(), Money::SCALE);
    }

    /**
     * The order status follows its items: delivered when all are delivered, shipped when all are at least shipped.
     */
    public function refreshStatus(): void
    {
        $statuses = array_unique(array_map(static fn (OrderItem $i) => $i->getStatus(), $this->items->toArray()));
        $this->status = match (true) {
            [] === $statuses => $this->getInitialStatus(),
            [self::STATUS_CANCELLED] === array_values($statuses) => self::STATUS_CANCELLED,
            [self::STATUS_REFUNDED] === array_values($statuses) => self::STATUS_REFUNDED,
            [] === array_diff($statuses, [self::STATUS_DELIVERED, self::STATUS_CANCELLED, self::STATUS_REFUNDED]) => self::STATUS_DELIVERED,
            [] === array_diff($statuses, [self::STATUS_SHIPPED, self::STATUS_DELIVERED, self::STATUS_CANCELLED, self::STATUS_REFUNDED]) => self::STATUS_SHIPPED,
            default => $this->getInitialStatus(),
        };
    }

    /** First status of the flow: "paid" for a card order, "placed" for cash on delivery. */
    public function getInitialStatus(): string
    {
        return PaymentMethod::CashOnDelivery === $this->paymentMethod ? self::STATUS_PLACED : self::STATUS_PAID;
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

    public function getPaymentMethod(): PaymentMethod
    {
        return $this->paymentMethod;
    }

    public function getPaymentStatus(): PaymentStatus
    {
        return $this->paymentStatus;
    }

    public function isCashOnDelivery(): bool
    {
        return PaymentMethod::CashOnDelivery === $this->paymentMethod;
    }

    /** Cash order still running whose money has not been collected (counts towards the buyer's pending limit). */
    public function isCashPending(): bool
    {
        return $this->isCashOnDelivery() && PaymentStatus::Pending === $this->paymentStatus
            && in_array($this->status, self::ACTIVE_STATUSES, true);
    }

    /** The seller can confirm the cash only once every item is delivered. */
    public function isCashCollectable(): bool
    {
        return $this->isCashPending() && self::STATUS_DELIVERED === $this->status;
    }

    /** The parcel is on its way or at the door and the cash was not collected: the buyer can still refuse it. */
    public function isDeliveryRefusable(): bool
    {
        return $this->isCashPending() && in_array($this->status, [self::STATUS_SHIPPED, self::STATUS_DELIVERED], true);
    }

    public function getCardHolder(): ?string
    {
        return $this->cardHolder;
    }

    public function getCardLast4(): ?string
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

    /**
     * True when EVERY item of the order is sold by $seller. Cash on delivery is limited to single-seller orders, so
     * the seller who confirms the cash (or a refusal) is always the only one concerned by the whole order.
     */
    public function isSoldOnlyBy(User $seller): bool
    {
        return !$this->items->isEmpty() && count($this->getItemsForSeller($seller)) === $this->items->count();
    }

    /** A buyer may cancel only while nothing has shipped yet. */
    public function isCancellable(): bool
    {
        return in_array($this->status, self::AWAITING_SHIPMENT, true);
    }

    /**
     * Admin (simulated) refund: an order that is not already cancelled or refunded AND whose money was received.
     * An uncollected cash order has nothing to refund (it is cancelled or refused instead).
     */
    public function isRefundable(): bool
    {
        return in_array($this->status, self::SOLD_STATUSES, true)
            && in_array($this->paymentStatus->value, PaymentStatus::settledValues(), true);
    }

    /**
     * SECURITY: state machine. Only called by OrderManager, inside the DB transaction that also restores the stock.
     */
    public function markCancelled(): void
    {
        if (!$this->isCancellable()) {
            throw new \DomainException('Only an order that has not shipped yet can be cancelled.');
        }
        foreach ($this->items as $item) {
            $item->forceStatus(self::STATUS_CANCELLED);
        }
        $this->status = self::STATUS_CANCELLED;
        // Card: the (simulated) charge is given back. Cash: nothing was ever received, it stays "pending".
        if (PaymentStatus::Paid === $this->paymentStatus) {
            $this->paymentStatus = PaymentStatus::Refunded;
        }
    }

    /**
     * SECURITY: state machine, cash on delivery only: pending -> collected, once, and only when delivered.
     * Called by OrderManager under a row lock, so a double click can't run it twice.
     */
    public function markCashCollected(): void
    {
        if (!$this->isCashCollectable()) {
            throw new \DomainException('Cash can only be collected once, on a delivered cash-on-delivery order.');
        }
        $this->paymentStatus = PaymentStatus::Collected;
    }

    /**
     * SECURITY: state machine, cash on delivery only: the buyer refused the parcel. The order is cancelled; the
     * stock is restored by OrderManager in the same transaction.
     */
    public function markRefusedAtDelivery(): void
    {
        if (!$this->isDeliveryRefusable()) {
            throw new \DomainException('Only a shipped cash-on-delivery order that is not paid yet can be refused.');
        }
        foreach ($this->items as $item) {
            $item->forceStatus(self::STATUS_CANCELLED);
        }
        $this->status = self::STATUS_CANCELLED;
    }

    public function markRefunded(): void
    {
        if (!$this->isRefundable()) {
            throw new \DomainException('This order cannot be refunded.');
        }
        foreach ($this->items as $item) {
            $item->forceStatus(self::STATUS_REFUNDED);
        }
        $this->status = self::STATUS_REFUNDED;
        $this->paymentStatus = PaymentStatus::Refunded;
    }

    public function setShippingAddress(Address $address): void
    {
        $this->shippingName = $address->getFullName();
        $this->shippingLine1 = $address->getLine1();
        $this->shippingLine2 = $address->getLine2();
        $this->shippingPostalCode = $address->getPostalCode();
        $this->shippingCity = $address->getCity();
        $this->shippingCountry = $address->getCountry();
    }

    public function getShippingName(): ?string
    {
        return $this->shippingName;
    }

    /** @return list<string> */
    public function getShippingLines(): array
    {
        if (null === $this->shippingName) {
            return [];
        }

        return array_values(array_filter([
            $this->shippingName,
            $this->shippingLine1,
            $this->shippingLine2,
            trim($this->shippingPostalCode.' '.$this->shippingCity),
            Address::COUNTRIES[$this->shippingCountry] ?? $this->shippingCountry,
        ]));
    }

    /** Short human order number (derived from the UUID, display only). */
    public function getNumber(): string
    {
        return 'MS-'.strtoupper(substr($this->uuid->toBase58(), 0, 8));
    }

    /** Fixtures only: spreads demo orders over time so the charts have history. */
    public function backdate(\DateTimeImmutable $createdAt): void
    {
        $this->createdAt = $createdAt;
    }
}
