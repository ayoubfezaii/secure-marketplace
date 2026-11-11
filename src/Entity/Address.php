<?php

namespace App\Entity;

use App\Repository\AddressRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A buyer's saved shipping address (max MAX_PER_USER).
 *
 * SECURITY: the owner is set by the server (constructor), never from the form (rule 9); public URLs use the UUID
 * and every action goes through AddressVoter, so another buyer's address can't be read or changed (rule 4).
 */
#[ORM\Entity(repositoryClass: AddressRepository::class)]
class Address
{
    public const MAX_PER_USER = 5;

    /** Supported shipping countries (ISO 3166-1 alpha-2). Whitelist (rule 15). */
    public const COUNTRIES = [
        'FR' => 'France', 'BE' => 'Belgium', 'CH' => 'Switzerland', 'LU' => 'Luxembourg', 'DE' => 'Germany',
        'ES' => 'Spain', 'IT' => 'Italy', 'NL' => 'Netherlands', 'PT' => 'Portugal', 'GB' => 'United Kingdom',
        'MA' => 'Morocco', 'TN' => 'Tunisia', 'DZ' => 'Algeria', 'CA' => 'Canada', 'US' => 'United States',
    ];

    private const TEXT = '/^[\p{L}\p{N} .,\'\/#°()-]+$/u';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $uuid;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(length: 40)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 40)]
    #[Assert\Regex(pattern: self::TEXT, message: 'Please use letters, numbers and basic punctuation only.')]
    private string $label = 'Home';

    #[ORM\Column(length: 100)]
    #[Assert\NotBlank]
    #[Assert\Length(min: 2, max: 100)]
    #[Assert\Regex(pattern: "/^[\\p{L}][\\p{L} .'-]*$/u", message: 'Please enter a valid name.')]
    private string $fullName = '';

    #[ORM\Column(length: 120)]
    #[Assert\NotBlank]
    #[Assert\Length(min: 3, max: 120)]
    #[Assert\Regex(pattern: self::TEXT, message: 'Please use letters, numbers and basic punctuation only.')]
    private string $line1 = '';

    #[ORM\Column(length: 120, nullable: true)]
    #[Assert\Length(max: 120)]
    #[Assert\Regex(pattern: self::TEXT, message: 'Please use letters, numbers and basic punctuation only.')]
    private ?string $line2 = null;

    #[ORM\Column(length: 12)]
    #[Assert\NotBlank]
    #[Assert\Regex(pattern: '/^[A-Za-z0-9 -]{3,12}$/', message: 'Please enter a valid postal code.')]
    private string $postalCode = '';

    #[ORM\Column(length: 80)]
    #[Assert\NotBlank]
    #[Assert\Length(min: 2, max: 80)]
    #[Assert\Regex(pattern: "/^[\\p{L}][\\p{L} .'-]*$/u", message: 'Please enter a valid city.')]
    private string $city = '';

    #[ORM\Column(length: 2)]
    #[Assert\NotBlank]
    #[Assert\Choice(callback: [self::class, 'countryCodes'], message: 'We do not ship to this country yet.')]
    private string $country = 'FR';

    #[ORM\Column(length: 20, nullable: true)]
    #[Assert\Regex(pattern: '/^\+?[0-9 ]{6,20}$/', message: 'Please enter a valid phone number.')]
    private ?string $phone = null;

    #[ORM\Column(options: ['default' => false])]
    private bool $isDefault = false;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(User $user)
    {
        $this->uuid = Uuid::v4();
        $this->user = $user;
        $this->createdAt = new \DateTimeImmutable();
    }

    /** @return list<string> */
    public static function countryCodes(): array
    {
        return array_keys(self::COUNTRIES);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUuid(): Uuid
    {
        return $this->uuid;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function setLabel(?string $label): static
    {
        $this->label = trim((string) $label);

        return $this;
    }

    public function getFullName(): string
    {
        return $this->fullName;
    }

    public function setFullName(?string $fullName): static
    {
        $this->fullName = trim((string) $fullName);

        return $this;
    }

    public function getLine1(): string
    {
        return $this->line1;
    }

    public function setLine1(?string $line1): static
    {
        $this->line1 = trim((string) $line1);

        return $this;
    }

    public function getLine2(): ?string
    {
        return $this->line2;
    }

    public function setLine2(?string $line2): static
    {
        $line2 = null !== $line2 ? trim($line2) : null;
        $this->line2 = '' === $line2 ? null : $line2;

        return $this;
    }

    public function getPostalCode(): string
    {
        return $this->postalCode;
    }

    public function setPostalCode(?string $postalCode): static
    {
        $this->postalCode = strtoupper(trim((string) $postalCode));

        return $this;
    }

    public function getCity(): string
    {
        return $this->city;
    }

    public function setCity(?string $city): static
    {
        $this->city = trim((string) $city);

        return $this;
    }

    public function getCountry(): string
    {
        return $this->country;
    }

    public function getCountryName(): string
    {
        return self::COUNTRIES[$this->country] ?? $this->country;
    }

    public function setCountry(?string $country): static
    {
        $this->country = strtoupper(trim((string) $country));

        return $this;
    }

    public function getPhone(): ?string
    {
        return $this->phone;
    }

    public function setPhone(?string $phone): static
    {
        $phone = null !== $phone ? trim($phone) : null;
        $this->phone = '' === $phone ? null : $phone;

        return $this;
    }

    public function isDefault(): bool
    {
        return $this->isDefault;
    }

    public function setIsDefault(bool $isDefault): static
    {
        $this->isDefault = $isDefault;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /** @return list<string> lines for display (each auto-escaped by Twig) */
    public function getLines(): array
    {
        return array_values(array_filter([$this->fullName, $this->line1, $this->line2, $this->postalCode.' '.$this->city, $this->getCountryName()]));
    }
}
