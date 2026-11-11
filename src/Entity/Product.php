<?php

namespace App\Entity;

use App\Repository\ProductRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

#[ORM\Entity(repositoryClass: ProductRepository::class)]
#[ORM\Index(name: 'idx_product_active_created', columns: ['is_active', 'created_at'])]
#[ORM\HasLifecycleCallbacks]
class Product
{
    /** Up to 4 images per product (same secure upload rules for each one, rule 7). */
    public const MAX_IMAGES = 4;

    /** "NEW" badge for products listed during the last N days. */
    public const NEW_DAYS = 14;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    // SECURITY: public identifier in URLs; sequential ids are never exposed (rule 4, IDOR / enumeration).
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $uuid;

    // SECURITY: set by the controller from the logged-in user, never from the request (rule 9).
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $seller;

    // SECURITY: length validated server side (rule 15); displayed with auto-escaping (rule 2).
    #[ORM\Column(length: 120)]
    #[Assert\NotBlank]
    #[Assert\Length(min: 3, max: 120)]
    private string $name = '';

    // SECURITY: plain text only, max 5000. Never rendered with |raw; line breaks via CSS pre-line (rule 2).
    #[ORM\Column(type: Types::TEXT)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 5000)]
    private string $description = '';

    // Decimal stored as string by Doctrine: no float rounding errors on money.
    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2)]
    #[Assert\NotBlank]
    #[Assert\Positive]
    #[Assert\LessThanOrEqual(100000)]
    private string $price = '0.00';

    // "Was" price for the sale badge. Display only: checkout always charges $price read from the DB (rule 10).
    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2, nullable: true)]
    #[Assert\Positive]
    #[Assert\LessThanOrEqual(100000)]
    private ?string $compareAtPrice = null;

    #[ORM\Column]
    #[Assert\NotNull]
    #[Assert\PositiveOrZero]
    #[Assert\LessThanOrEqual(1000000)]
    private int $stock = 0;

    // SECURITY (rule 15): a real Category row (admin-managed), not free text. RESTRICT: a category that still
    // has products can't be deleted (the admin UI checks it too).
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    #[Assert\NotNull(message: 'Please choose a category.')]
    // Not initialised until the seller picks one (the validator reports an uninitialised property as null).
    private Category $category;

    /** @var Collection<int, ProductImage> */
    #[ORM\OneToMany(targetEntity: ProductImage::class, mappedBy: 'product', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC'])]
    #[Assert\Count(max: self::MAX_IMAGES, maxMessage: 'A product can have at most {{ limit }} images.')]
    private Collection $images;

    #[ORM\Column(options: ['default' => true])]
    private bool $isActive = true;

    // Denormalised rating, recomputed by ProductRatingUpdater whenever a review is added or removed. Lets the
    // catalogue filter and sort by rating with a plain indexed column (no HAVING over a join).
    #[ORM\Column(type: Types::DECIMAL, precision: 3, scale: 2, options: ['default' => '0.00'])]
    private string $ratingAverage = '0.00';

    #[ORM\Column(options: ['default' => 0])]
    private int $ratingCount = 0;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(User $seller)
    {
        $this->uuid = Uuid::v4();
        $this->seller = $seller;
        $this->images = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    #[Assert\Callback]
    public function validateCompareAtPrice(ExecutionContextInterface $context): void
    {
        // SECURITY/consistency: a "was" price must be higher than the real price, otherwise the sale badge would lie.
        if (null !== $this->compareAtPrice && is_numeric($this->compareAtPrice) && is_numeric($this->price)
            && bccomp($this->compareAtPrice, $this->price, 2) <= 0) {
            $context->buildViolation('The original price must be higher than the current price (or empty).')
                ->atPath('compareAtPrice')->addViolation();
        }
    }

    #[ORM\PreUpdate]
    public function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUuid(): Uuid
    {
        return $this->uuid;
    }

    public function getSeller(): User
    {
        return $this->seller;
    }

    public function getShop(): ?Shop
    {
        return $this->seller->getShop();
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(?string $name): static
    {
        $this->name = trim((string) $name);

        return $this;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = trim((string) $description);

        return $this;
    }

    public function getPrice(): string
    {
        return $this->price;
    }

    public function setPrice(?string $price): static
    {
        $this->price = (string) $price;

        return $this;
    }

    public function getCompareAtPrice(): ?string
    {
        return $this->compareAtPrice;
    }

    public function setCompareAtPrice(?string $compareAtPrice): static
    {
        $this->compareAtPrice = null === $compareAtPrice || '' === $compareAtPrice ? null : $compareAtPrice;

        return $this;
    }

    public function isOnSale(): bool
    {
        return null !== $this->compareAtPrice && bccomp($this->compareAtPrice, $this->price, 2) > 0;
    }

    public function getDiscountPercent(): int
    {
        if (!$this->isOnSale()) {
            return 0;
        }

        return (int) round((1 - (float) $this->price / (float) $this->compareAtPrice) * 100);
    }

    public function isNew(): bool
    {
        return $this->createdAt > new \DateTimeImmutable(sprintf('-%d days', self::NEW_DAYS));
    }

    public function getStock(): int
    {
        return $this->stock;
    }

    public function setStock(?int $stock): static
    {
        $this->stock = (int) $stock;

        return $this;
    }

    /**
     * Only called inside the checkout transaction, on a row locked with PESSIMISTIC_WRITE.
     */
    public function decreaseStock(int $quantity): void
    {
        if ($quantity < 1 || $quantity > $this->stock) {
            throw new \DomainException('Insufficient stock.');
        }
        $this->stock -= $quantity;
    }

    /**
     * Cancellation / refund: puts units back, inside the same transaction as the status change.
     */
    public function restoreStock(int $quantity): void
    {
        if ($quantity < 1) {
            throw new \DomainException('Invalid quantity.');
        }
        $this->stock += $quantity;
    }

    public function getCategory(): ?Category
    {
        return $this->category ?? null;
    }

    public function setCategory(?Category $category): static
    {
        // An empty submission keeps the current category (NotNull reports it on a new product).
        if (null !== $category) {
            $this->category = $category;
        }

        return $this;
    }

    public function getCategoryLabel(): string
    {
        return $this->getCategory()?->getName() ?? 'Other';
    }

    /** @return Collection<int, ProductImage> */
    public function getImages(): Collection
    {
        return $this->images;
    }

    public function canAddImage(): bool
    {
        return $this->images->count() < self::MAX_IMAGES;
    }

    public function addImage(string $filename): ProductImage
    {
        if (!$this->canAddImage()) {
            throw new \DomainException(sprintf('A product can have at most %d images.', self::MAX_IMAGES));
        }
        $image = new ProductImage($this, $filename, $this->images->count());
        $this->images->add($image);

        return $image;
    }

    public function removeImage(ProductImage $image): void
    {
        $this->images->removeElement($image);
        $position = 0;
        foreach ($this->images as $remaining) {
            $remaining->setPosition($position++);
        }
    }

    public function getMainImageFilename(): ?string
    {
        $first = $this->images->first();

        return false !== $first ? $first->getFilename() : null;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function setIsActive(bool $isActive): static
    {
        $this->isActive = $isActive;

        return $this;
    }

    public function getRatingAverage(): float
    {
        return (float) $this->ratingAverage;
    }

    public function getRatingCount(): int
    {
        return $this->ratingCount;
    }

    public function setRatingSummary(float $average, int $count): void
    {
        $this->ratingAverage = number_format(max(0, min(5, $average)), 2, '.', '');
        $this->ratingCount = max(0, $count);
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /** Fixtures only: spreads demo products over time. */
    public function backdate(\DateTimeImmutable $createdAt): void
    {
        $this->createdAt = $createdAt;
        $this->updatedAt = $createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /** True if this product can be bought right now. */
    public function isPurchasable(): bool
    {
        return $this->isActive && $this->stock > 0;
    }
}
