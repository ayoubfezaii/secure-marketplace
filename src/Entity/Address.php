<?php

/**
 * FILE: src/Entity/Address.php
 * WHAT: the Address entity = one saved shipping address (one row of the `address` table).
 * ROLE IN THE PROJECT: lets a buyer keep up to 5 addresses and pick one at checkout.
 * USED BY: Buyer\AddressController (buyer address book), CheckoutController (address step), AddressVoter.
 */

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
    // A buyer can save at most 5 addresses.
    public const MAX_PER_USER = 5;

    /** Supported shipping countries (ISO 3166-1 alpha-2). Whitelist (rule 15). */
    public const COUNTRIES = [
        'FR' => 'France', 'BE' => 'Belgium', 'CH' => 'Switzerland', 'LU' => 'Luxembourg', 'DE' => 'Germany',
        'ES' => 'Spain', 'IT' => 'Italy', 'NL' => 'Netherlands', 'PT' => 'Portugal', 'GB' => 'United Kingdom',
        'MA' => 'Morocco', 'TN' => 'Tunisia', 'DZ' => 'Algeria', 'CA' => 'Canada', 'US' => 'United States',
    ];

    // Allowed characters for free-text fields: letters, digits, spaces and basic punctuation.
    // SECURITY: validation - characters like < > " are refused, so no HTML or script can be stored here.
    private const TEXT = '/^[\p{L}\p{N} .,\'\/#°()-]+$/u';

    // Internal auto-increment primary key (never in URLs).
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    // SECURITY: IDOR - random UUID used in URLs instead of the id, so addresses cannot be guessed one by one.
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $uuid;

    // Owner of the address. CASCADE: the addresses are deleted with the account.
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    // Short label chosen by the buyer ("Home", "Office").
    #[ORM\Column(length: 40)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 40)]
    #[Assert\Regex(pattern: self::TEXT, message: 'Please use letters, numbers and basic punctuation only.')]
    private string $label = 'Home';

    // Name of the person who receives the parcel (letters, spaces, dots, apostrophes, dashes).
    #[ORM\Column(length: 100)]
    #[Assert\NotBlank]
    #[Assert\Length(min: 2, max: 100)]
    #[Assert\Regex(pattern: "/^[\\p{L}][\\p{L} .'-]*$/u", message: 'Please enter a valid name.')]
    private string $fullName = '';

    // Street address, line 1 (required) and line 2 (optional).
    #[ORM\Column(length: 120)]
    #[Assert\NotBlank]
    #[Assert\Length(min: 3, max: 120)]
    #[Assert\Regex(pattern: self::TEXT, message: 'Please use letters, numbers and basic punctuation only.')]
    private string $line1 = '';

    #[ORM\Column(length: 120, nullable: true)]
    #[Assert\Length(max: 120)]
    #[Assert\Regex(pattern: self::TEXT, message: 'Please use letters, numbers and basic punctuation only.')]
    private ?string $line2 = null;

    // Postal code: 3 to 12 letters, digits, spaces or dashes.
    #[ORM\Column(length: 12)]
    #[Assert\NotBlank]
    #[Assert\Regex(pattern: '/^[A-Za-z0-9 -]{3,12}$/', message: 'Please enter a valid postal code.')]
    private string $postalCode = '';

    // City name (letters only, with spaces, dots, apostrophes, dashes).
    #[ORM\Column(length: 80)]
    #[Assert\NotBlank]
    #[Assert\Length(min: 2, max: 80)]
    #[Assert\Regex(pattern: "/^[\\p{L}][\\p{L} .'-]*$/u", message: 'Please enter a valid city.')]
    private string $city = '';

    // SECURITY: validation - the country must be one of the codes in COUNTRIES (whitelist), nothing else.
    #[ORM\Column(length: 2)]
    #[Assert\NotBlank]
    #[Assert\Choice(callback: [self::class, 'countryCodes'], message: 'We do not ship to this country yet.')]
    private string $country = 'FR';

    // Optional phone number for the delivery.
    #[ORM\Column(length: 20, nullable: true)]
    #[Assert\Regex(pattern: '/^\+?[0-9 ]{6,20}$/', message: 'Please enter a valid phone number.')]
    private ?string $phone = null;

    // True for the address preselected at checkout (one per buyer).
    #[ORM\Column(options: ['default' => false])]
    private bool $isDefault = false;

    // When the address was created.
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    /**
     * Creates an address for $user. The owner is fixed here and can never be changed later.
     *
     * @param User $user the logged-in buyer
     */
    public function __construct(User $user)
    {
        $this->uuid = Uuid::v4();
        $this->user = $user;
        $this->createdAt = new \DateTimeImmutable();
    }

    // Returns the list of allowed country codes. Used by the Choice constraint on $country.
    /** @return list<string> */
    public static function countryCodes(): array
    {
        return array_keys(self::COUNTRIES);
    }

    // ----- Getters and setters. Setters only clean the text (trim); the validator checks it afterwards. -----

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

    /** Postal codes are stored in upper case. */
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

    /** Full country name for display ("TN" becomes "Tunisia"). */
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
