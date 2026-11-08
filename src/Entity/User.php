<?php

namespace App\Entity;

use App\Repository\UserRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Scheb\TwoFactorBundle\Model\Totp\TotpConfiguration;
use Scheb\TwoFactorBundle\Model\Totp\TotpConfigurationInterface;
use Scheb\TwoFactorBundle\Model\Totp\TwoFactorInterface;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: '`user`')]
// SECURITY: the DB unique index guarantees one account per email even under concurrent registrations (rule 15).
#[ORM\UniqueConstraint(name: 'UNIQ_IDENTIFIER_EMAIL', fields: ['email'])]
#[UniqueEntity(fields: ['email'], message: 'This email address is already registered.')]
class User implements UserInterface, PasswordAuthenticatedUserInterface, TwoFactorInterface
{
    public const ROLE_BUYER = 'ROLE_BUYER';
    public const ROLE_SELLER = 'ROLE_SELLER';
    public const ROLE_ADMIN = 'ROLE_ADMIN';

    // SECURITY: lockout policy (rule 6): 5 consecutive failures lock the account for 15 minutes.
    public const MAX_FAILED_ATTEMPTS = 5;
    public const LOCKOUT_DURATION = '+15 minutes';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    // SECURITY: random, non-guessable public identifier; integer ids are never exposed in URLs (rule 4, IDOR).
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $uuid;

    // SECURITY: strict format and length validation on the login identifier (rule 15).
    #[ORM\Column(length: 180)]
    #[Assert\NotBlank]
    #[Assert\Email(mode: Assert\Email::VALIDATION_MODE_STRICT)]
    #[Assert\Length(max: 180)]
    private string $email = '';

    /**
     * @var list<string>
     */
    #[ORM\Column]
    private array $roles = [];

    /**
     * Argon2id hash only, never the plain password.
     */
    #[ORM\Column]
    private string $password = '';

    #[ORM\Column(options: ['default' => 0])]
    private int $failedAttempts = 0;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lockedUntil = null;

    // SECURITY: account blocked by an administrator (separate from the temporary brute-force lockout above):
    // refused at login AND existing sessions are ended (LockedUserSubscriber).
    #[ORM\Column(options: ['default' => false])]
    private bool $blocked = false;

    #[ORM\Column(options: ['default' => false])]
    private bool $isVerified = false;

    // Public name shown on products and reviews, so the email address is never displayed publicly.
    #[ORM\Column(length: 60, nullable: true)]
    #[Assert\Length(min: 2, max: 60)]
    #[Assert\Regex(pattern: '/^[\p{L}\p{N} .\'_-]+$/u', message: 'Use letters, numbers, spaces, dots, apostrophes, dashes or underscores only.')]
    private ?string $displayName = null;

    #[ORM\Column(length: 20, nullable: true)]
    #[Assert\Regex(pattern: '/^\+?[0-9 ]{6,20}$/', message: 'Please enter a valid phone number.')]
    private ?string $phone = null;

    // SECURITY (rule 11): seller IBAN encrypted with libsodium (FieldEncryptor). Never stored in clear text.
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $ibanEncrypted = null;

    // Last 4 characters only, to display a masked IBAN without decrypting it.
    #[ORM\Column(length: 4, nullable: true)]
    private ?string $ibanLast4 = null;

    // SECURITY: TOTP shared secret (rule 5). Never shown again after setup and never sent to the client except
    // inside the one-time enrolment QR code.
    #[ORM\Column(length: 64, nullable: true)]
    private ?string $totpSecret = null;

    #[ORM\Column(options: ['default' => false])]
    private bool $totpEnabled = false;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->uuid = Uuid::v4();
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUuid(): Uuid
    {
        return $this->uuid;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function setEmail(string $email): static
    {
        // SECURITY: normalise so "Alice@x.com" and "alice@x.com" can't become two accounts.
        $this->email = mb_strtolower(trim($email));

        return $this;
    }

    public function getUserIdentifier(): string
    {
        return (string) $this->email;
    }

    /**
     * @return list<string>
     */
    public function getRoles(): array
    {
        $roles = $this->roles;
        $roles[] = 'ROLE_USER';

        return array_values(array_unique($roles));
    }

    /**
     * @param list<string> $roles
     */
    public function setRoles(array $roles): static
    {
        $this->roles = $roles;

        return $this;
    }

    public function getPassword(): string
    {
        return $this->password;
    }

    public function setPassword(string $password): static
    {
        $this->password = $password;

        return $this;
    }

    public function getFailedAttempts(): int
    {
        return $this->failedAttempts;
    }

    public function getLockedUntil(): ?\DateTimeImmutable
    {
        return $this->lockedUntil;
    }

    /** Temporary brute-force lockout OR admin block. */
    public function isLocked(): bool
    {
        return $this->blocked || (null !== $this->lockedUntil && $this->lockedUntil > new \DateTimeImmutable());
    }

    public function isBlocked(): bool
    {
        return $this->blocked;
    }

    public function block(): void
    {
        $this->blocked = true;
    }

    /** Admin unlock: lifts both the admin block and any brute-force lockout. */
    public function unblock(): void
    {
        $this->blocked = false;
        $this->resetFailedLogins();
    }

    /**
     * Records a failed login and locks the account once the threshold is reached.
     */
    public function registerFailedLogin(): void
    {
        ++$this->failedAttempts;

        if ($this->failedAttempts >= self::MAX_FAILED_ATTEMPTS) {
            // SECURITY: lock the account; counter restarts so the next lock needs 5 new failures.
            $this->lockedUntil = new \DateTimeImmutable(self::LOCKOUT_DURATION);
            $this->failedAttempts = 0;
        }
    }

    public function resetFailedLogins(): void
    {
        $this->failedAttempts = 0;
        $this->lockedUntil = null;
    }

    public function isVerified(): bool
    {
        return $this->isVerified;
    }

    public function setIsVerified(bool $isVerified): static
    {
        $this->isVerified = $isVerified;

        return $this;
    }

    public function getDisplayName(): ?string
    {
        return $this->displayName;
    }

    public function setDisplayName(?string $displayName): static
    {
        $displayName = null !== $displayName ? trim($displayName) : null;
        $this->displayName = '' === $displayName ? null : $displayName;

        return $this;
    }

    /**
     * SECURITY: name safe to show to other users. Never the email (privacy, and it would help phishing/enumeration).
     */
    public function getPublicName(): string
    {
        return $this->displayName ?? 'User '.substr($this->uuid->toRfc4122(), 0, 8);
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

    public function getIbanEncrypted(): ?string
    {
        return $this->ibanEncrypted;
    }

    /**
     * @param string $ciphertext output of FieldEncryptor::encrypt(), never the clear IBAN
     */
    public function setEncryptedIban(string $ciphertext, string $last4): static
    {
        $this->ibanEncrypted = $ciphertext;
        $this->ibanLast4 = $last4;

        return $this;
    }

    public function removeIban(): void
    {
        $this->ibanEncrypted = null;
        $this->ibanLast4 = null;
    }

    public function getMaskedIban(): ?string
    {
        return null !== $this->ibanLast4 ? '•••• •••• •••• '.$this->ibanLast4 : null;
    }

    public function isTotpAuthenticationEnabled(): bool
    {
        return $this->totpEnabled && null !== $this->totpSecret;
    }

    public function getTotpAuthenticationUsername(): string
    {
        return (string) $this->email;
    }

    public function getTotpAuthenticationConfiguration(): ?TotpConfigurationInterface
    {
        if (null === $this->totpSecret) {
            return null;
        }

        // SECURITY: standard RFC 6238 parameters (SHA1, 30 s, 6 digits) so every authenticator app works.
        return new TotpConfiguration($this->totpSecret, TotpConfiguration::ALGORITHM_SHA1, 30, 6);
    }

    public function getTotpSecret(): ?string
    {
        return $this->totpSecret;
    }

    /**
     * Sets a candidate secret WITHOUT enabling 2FA; enableTotp() is only called after the user proved
     * they can generate a valid code with it.
     */
    public function setTotpSecret(?string $totpSecret): static
    {
        $this->totpSecret = $totpSecret;

        return $this;
    }

    public function enableTotp(): void
    {
        $this->totpEnabled = true;
    }

    public function disableTotp(): void
    {
        // SECURITY: the secret is wiped, so re-enabling always generates a fresh one.
        $this->totpEnabled = false;
        $this->totpSecret = null;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /**
     * Ensure the session doesn't contain the actual password hash.
     */
    public function __serialize(): array
    {
        $data = (array) $this;
        // SECURITY: store a CRC of the hash in the session instead of the hash itself;
        // a password change still invalidates other sessions.
        $data["\0".self::class."\0password"] = hash('crc32c', (string) $this->password);
        // SECURITY: the TOTP secret never lands in session files; the user is reloaded from the DB on each request.
        $data["\0".self::class."\0totpSecret"] = null;
        $data["\0".self::class."\0ibanEncrypted"] = null;

        return $data;
    }

    #[\Deprecated]
    public function eraseCredentials(): void
    {
        // No plain-text credentials are stored on the entity.
    }
}
