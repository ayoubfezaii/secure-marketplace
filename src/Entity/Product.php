<?php

/**
 * FILE: src/Entity/Product.php
 * WHAT: the Product entity = one item for sale (one row of the `product` table).
 * ROLE IN THE PROJECT: holds the name, description, price, stock, category and images of a product, plus the
 *   validation rules that protect them.
 * USED BY: the catalogue and product page (visitor, buyer), the cart and checkout (buyer),
 *   the seller product pages (seller owns and edits it) and admin moderation (admin).
 */

namespace App\Entity;

use App\Service\Money;
use App\Repository\ProductRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * A product sold by one seller.
 * The index on (is_active, created_at) makes the "latest active products" queries fast.
 * HasLifecycleCallbacks lets Doctrine call touch() automatically before each update.
 */
#[ORM\Entity(repositoryClass: ProductRepository::class)]
#[ORM\Index(name: 'idx_product_active_created', columns: ['is_active', 'created_at'])]
#[ORM\HasLifecycleCallbacks]
class Product
{
    /** Up to 4 images per product (same secure upload rules for each one, rule 7). */
    public const MAX_IMAGES = 4;

    /** "NEW" badge for products listed during the last N days. */
    public const NEW_DAYS = 14;

    // Internal auto-increment primary key. Used only for joins, never shown in a URL.
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

    // Decimal stored as string by Doctrine: no float rounding errors on money. Dinars, 3 decimals (millimes).
    // SECURITY (rule 15): strictly positive, at most 300 000 DT, at most 3 decimals, digits only (no "1e9", no sign).
    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 3)]
    #[Assert\NotBlank]
    #[Assert\Regex(pattern: Money::PATTERN, message: 'Enter a price in dinars with at most 3 decimals, for example 35.500.')]
    #[Assert\Positive(message: 'The price must be greater than 0.')]
    #[Assert\LessThanOrEqual(Money::MAX_PRICE, message: 'The price cannot exceed 300 000 DT.')]
    private string $price = Money::ZERO;

    // "Was" price for the sale badge. Display only: checkout always charges $price read from the DB (rule 10).
    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 3, nullable: true)]
    #[Assert\Regex(pattern: Money::PATTERN, message: 'Enter a price in dinars with at most 3 decimals, for example 49.900.')]
    #[Assert\Positive(message: 'The original price must be greater than 0.')]
    #[Assert\LessThanOrEqual(Money::MAX_PRICE, message: 'The original price cannot exceed 300 000 DT.')]
    private ?string $compareAtPrice = null;

    // Number of units that can still be sold.
    // SECURITY: validation - never negative and never absurdly large.
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

    // The pictures of the product (max 4), sorted by position. Deleting the product also deletes its image rows.
    /** @var Collection<int, ProductImage> */
    #[ORM\OneToMany(targetEntity: ProductImage::class, mappedBy: 'product', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC'])]
    #[Assert\Count(max: self::MAX_IMAGES, maxMessage: 'A product can have at most {{ limit }} images.')]
    private Collection $images;

    // false = hidden from the catalogue (the seller paused it, or an admin deactivated it).
    #[ORM\Column(options: ['default' => true])]
    private bool $isActive = true;

    // Denormalised rating, recomputed by ProductRatingUpdater whenever a review is added or removed. Lets the
    // catalogue filter and sort by rating with a plain indexed column (no HAVING over a join).
    #[ORM\Column(type: Types::DECIMAL, precision: 3, scale: 2, options: ['default' => '0.00'])]
    private string $ratingAverage = '0.00';

    // Number of reviews (kept here for the same reason as ratingAverage).
    #[ORM\Column(options: ['default' => 0])]
    private int $ratingCount = 0;

    // Creation date and date of the last change.
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    /**
     * Creates a product that belongs to $seller.
     * SECURITY: mass assignment / IDOR - the owner is given once here by the controller (the logged-in seller).
     * There is no setSeller() method, so a form can never change the owner.
     *
     * @param User $seller the logged-in seller
     */
    public function __construct(User $seller)
    {
        $this->uuid = Uuid::v4();
        $this->seller = $seller;
        $this->images = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    /**
     * Extra validation rule. The Symfony Validator calls it together with the attribute constraints above.
     *
     * @param ExecutionContextInterface $context where the error message is added
     */
    #[Assert\Callback]
    public function validateCompareAtPrice(ExecutionContextInterface $context): void
    {
        // SECURITY/consistency: a "was" price must be higher than the real price, otherwise the sale badge would lie.
        if (null !== $this->compareAtPrice && is_numeric($this->compareAtPrice) && is_numeric($this->price)
            && bccomp($this->compareAtPrice, $this->price, Money::SCALE) <= 0) {
            $context->buildViolation('The original price must be higher than the current price (or empty).')
                ->atPath('compareAtPrice')->addViolation();
        }
    }

    /** Doctrine calls this just before every UPDATE: it refreshes the "last change" date. */
    #[ORM\PreUpdate]
    public function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    // ----- Getters and setters. Setters accept null (empty form field); the validator reports the error later. -----

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

    /** Shortcut: the public shop of the seller (can be null). */
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

    /** The price stays a string like "35.500": money is never stored in a float (rounding errors). */
    public function setPrice(?string $price): static
    {
        $this->price = (string) $price;

        return $this;
    }

    public function getCompareAtPrice(): ?string
    {
        return $this->compareAtPrice;
    }

    /** Sets the "was" price. An empty field means "no sale". */
    public function setCompareAtPrice(?string $compareAtPrice): static
    {
        $this->compareAtPrice = null === $compareAtPrice || '' === $compareAtPrice ? null : $compareAtPrice;

        return $this;
    }

    /** True when a higher "was" price exists, so the sale badge is shown. bccomp() compares decimals exactly. */
    public function isOnSale(): bool
    {
        return null !== $this->compareAtPrice && bccomp($this->compareAtPrice, $this->price, Money::SCALE) > 0;
    }

    /** Discount in percent for the badge. Display only, so a float is fine here. */
    public function getDiscountPercent(): int
    {
        if (!$this->isOnSale()) {
            return 0;
        }

        return (int) round((1 - (float) $this->price / (float) $this->compareAtPrice) * 100);
    }

    /** True when the product was listed less than NEW_DAYS days ago ("NEW" badge). */
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
     * SECURITY: price tampering / overselling - refuses a quantity bigger than the stock, so two buyers cannot
     * buy the same last unit and the stock can never become negative.
     * Only called inside the checkout transaction, on a row locked with PESSIMISTIC_WRITE.
     */
    public function decreaseStock(int $quantity): void
    {
        // Refuse zero, negative or too-large quantities.
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

    /** Returns the category, or null while none is chosen yet (new product form). */
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

    /** Category name for display ("Other" when missing). */
    public function getCategoryLabel(): string
    {
        return $this->getCategory()?->getName() ?? 'Other';
    }

    /** @return Collection<int, ProductImage> */
    public function getImages(): Collection
    {
        return $this->images;
    }

    /** True while one more image can be added (fewer than MAX_IMAGES). */
    public function canAddImage(): bool
    {
        return $this->images->count() < self::MAX_IMAGES;
    }

    /**
     * Attaches an already stored image to the product.
     * SECURITY: file upload - the limit of 4 images is checked here too, not only in the form.
     *
     * @param string $filename random name returned by ImageStorage
     *
     * @return ProductImage the new image row
     */
    public function addImage(string $filename): ProductImage
    {
        if (!$this->canAddImage()) {
            throw new \DomainException(sprintf('A product can have at most %d images.', self::MAX_IMAGES));
        }
        $image = new ProductImage($this, $filename, $this->images->count());
        $this->images->add($image);

        return $image;
    }

    /** Removes an image, then renumbers the remaining ones 0, 1, 2... */
    public function removeImage(ProductImage $image): void
    {
        $this->images->removeElement($image);
        $position = 0;
        foreach ($this->images as $remaining) {
            $remaining->setPosition($position++);
        }
    }

    /** File name of the first image (the cover), or null when the product has no image. */
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

    /**
     * Saves the rating summary. Called by ProductRatingUpdater after a review is added, changed or removed.
     * The average is forced between 0 and 5.
     */
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
