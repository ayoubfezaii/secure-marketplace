<?php

/**
 * FILE: src/Entity/Shop.php
 * WHAT: the Shop entity = the public shop page of a seller (name, address in the URL, description, logo).
 * ROLE IN THE PROJECT: gives each seller a public identity without showing their email.
 * USED BY: ShopController (public shop page, everyone), Seller\ShopController (the seller edits their shop),
 *   AccountManager (creates the shop when a buyer becomes a seller), ShopVoter.
 */

namespace App\Entity;

use App\Repository\ShopRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A seller's public shop (1-1 with the seller account). Created when a seller application is approved.
 *
 * SECURITY (rule 9): "owner" is set by the server (constructor), never by a form. The public URL uses the slug,
 * which is validated (letters, digits, dashes) and unique.
 */
#[ORM\Entity(repositoryClass: ShopRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_shop_slug', columns: ['slug'])]
#[ORM\UniqueConstraint(name: 'uniq_shop_owner', columns: ['owner_id'])]
#[UniqueEntity(fields: ['slug'], message: 'This shop address is already taken.')]
class Shop
{
    /** Slugs that would be confusing or collide with app words. */
    public const RESERVED_SLUGS = ['admin', 'seller', 'buyer', 'api', 'login', 'logout', 'register', 'new', 'edit', 'shop', 'maison-secure', 'support', 'security'];

    // Internal auto-increment primary key.
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    // The seller who owns the shop (one shop per seller, see the unique index on owner_id).
    #[ORM\OneToOne(inversedBy: 'shop')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $owner;

    // Shop name shown to customers.
    // SECURITY: validation - only letters, digits, spaces and a few symbols, so no HTML can be stored.
    #[ORM\Column(length: 80)]
    #[Assert\NotBlank]
    #[Assert\Length(min: 3, max: 80)]
    #[Assert\Regex(pattern: '/^[\p{L}\p{N} &.\'_-]+$/u', message: 'Use letters, numbers, spaces and & . \' _ - only.')]
    private string $name = '';

    // SECURITY (rule 15): strict slug format (it is used in public URLs).
    #[ORM\Column(length: 60)]
    #[Assert\NotBlank]
    #[Assert\Length(min: 3, max: 60)]
    #[Assert\Regex(pattern: '/^[a-z0-9]+(?:-[a-z0-9]+)*$/', message: 'Use lowercase letters, numbers and dashes only.')]
    #[Assert\Callback([self::class, 'validateSlug'])]
    private string $slug = '';

    // Plain text, auto-escaped on display (rule 2).
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Assert\Length(max: 2000)]
    private ?string $description = null;

    // Random name from ImageStorage (rule 7).
    #[ORM\Column(length: 64, nullable: true)]
    private ?string $logoFilename = null;

    // When the shop was created.
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    /**
     * @param User   $owner the seller who owns the shop
     * @param string $name  shop name
     * @param string $slug  text used in the public URL of the shop
     */
    public function __construct(User $owner, string $name, string $slug)
    {
        $this->owner = $owner;
        $this->setName($name);
        $this->setSlug($slug);
        $this->createdAt = new \DateTimeImmutable();
        // Keep both sides of the one-to-one link in sync ($user->getShop() works immediately).
        $owner->setShop($this);
    }

    /**
     * Extra validation rule for the slug: refuses reserved words such as "admin" or "login".
     * SECURITY: validation - a shop cannot take an address that looks like an official page of the site.
     */
    public static function validateSlug(mixed $value, \Symfony\Component\Validator\Context\ExecutionContextInterface $context): void
    {
        if (is_string($value) && in_array($value, self::RESERVED_SLUGS, true)) {
            $context->buildViolation('This shop address is reserved.')->addViolation();
        }
    }

    /** Builds a URL-safe slug from any name (used for the initial slug; the seller can change it). */
    public static function slugify(string $name): string
    {
        // Turn the name into plain lower-case ASCII with dashes ("Café Déco" becomes "cafe-deco").
        $ascii = (new \Symfony\Component\String\Slugger\AsciiSlugger())->slug($name)->lower()->toString();
        // Replace repeated dashes by one and remove dashes at both ends.
        $ascii = trim((string) preg_replace('/-+/', '-', $ascii), '-');

        // Keep at most 50 characters; use "shop" if nothing is left.
        return '' !== $ascii ? mb_substr($ascii, 0, 50) : 'shop';
    }

    // ----- Getters and setters. -----

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getOwner(): User
    {
        return $this->owner;
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

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function setSlug(?string $slug): static
    {
        $this->slug = mb_strtolower(trim((string) $slug));

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $description = null !== $description ? trim($description) : null;
        $this->description = '' === $description ? null : $description;

        return $this;
    }

    public function getLogoFilename(): ?string
    {
        return $this->logoFilename;
    }

    public function setLogoFilename(?string $logoFilename): static
    {
        $this->logoFilename = $logoFilename;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /** One or two capital letters taken from the shop name, used as a placeholder when there is no logo. */
    public function getInitials(): string
    {
        $words = preg_split('/\s+/u', $this->name) ?: [];
        $initials = '';
        foreach (array_slice($words, 0, 2) as $w) {
            $initials .= mb_strtoupper(mb_substr($w, 0, 1));
        }

        return '' !== $initials ? $initials : 'S';
    }
}
