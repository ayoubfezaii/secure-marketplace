<?php

namespace App\Entity;

use App\Repository\ProductRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: ProductRepository::class)]
#[ORM\Index(name: 'idx_product_active_created', columns: ['is_active', 'created_at'])]
#[ORM\HasLifecycleCallbacks]
class Product
{
    /** Whitelisted categories (value => label). The form and the search filter accept nothing else. */
    public const CATEGORIES = [
        'electronics' => 'Electronics',
        'books' => 'Books',
        'clothing' => 'Clothing',
        'home' => 'Home & Garden',
        'sports' => 'Sports',
        'toys' => 'Toys',
        'other' => 'Other',
    ];

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

    // SECURITY: plain text only, max 5000. Never rendered with |raw; line breaks via nl2br AFTER escaping (rule 2).
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

    #[ORM\Column]
    #[Assert\NotNull]
    #[Assert\PositiveOrZero]
    #[Assert\LessThanOrEqual(1000000)]
    private int $stock = 0;

    // SECURITY: whitelist (rule 15).
    #[ORM\Column(length: 32)]
    #[Assert\NotBlank]
    #[Assert\Choice(callback: [self::class, 'categoryKeys'])]
    private string $category = 'other';

    // Random server-generated name (see ImageUploader); never a user-provided filename.
    #[ORM\Column(length: 64, nullable: true)]
    private ?string $imageFilename = null;

    #[ORM\Column(options: ['default' => true])]
    private bool $isActive = true;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(User $seller)
    {
        $this->uuid = Uuid::v4();
        $this->seller = $seller;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    /** @return list<string> */
    public static function categoryKeys(): array
    {
        return array_keys(self::CATEGORIES);
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

    public function getCategory(): string
    {
        return $this->category;
    }

    public function getCategoryLabel(): string
    {
        return self::CATEGORIES[$this->category] ?? 'Other';
    }

    public function setCategory(?string $category): static
    {
        $this->category = (string) $category;

        return $this;
    }

    public function getImageFilename(): ?string
    {
        return $this->imageFilename;
    }

    public function setImageFilename(?string $imageFilename): static
    {
        $this->imageFilename = $imageFilename;

        return $this;
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

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
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
