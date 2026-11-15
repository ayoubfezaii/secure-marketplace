<?php

/**
 * FILE: src/Entity/User.php
 * WHAT: the User entity = one account of the site (one row of the `user` table).
 * ROLE IN THE PROJECT: central class for security. It holds the login (email), the hashed password, the role,
 *   the brute-force counters, the 2FA (TOTP) secret and the encrypted IBAN of sellers.
 * USED BY: Symfony Security at every login and on every request (all roles: buyer, seller, admin),
 *   the registration form, the profile pages and the admin "Users" panel.
 */

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

/**
 * A user account. It implements three interfaces:
 *  - UserInterface: Symfony can use it as "the logged-in user";
 *  - PasswordAuthenticatedUserInterface: the account has a hashed password;
 *  - TwoFactorInterface: scheb/2fa-bundle can ask it for a TOTP code (2FA).
 */
#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: '`user`')]
// SECURITY: the DB unique index guarantees one account per email even under concurrent registrations (rule 15).
#[ORM\UniqueConstraint(name: 'UNIQ_IDENTIFIER_EMAIL', fields: ['email'])]
#[UniqueEntity(fields: ['email'], message: 'This email address is already registered.')]
class User implements UserInterface, PasswordAuthenticatedUserInterface, TwoFactorInterface
{
    // The three business roles. An account has exactly ONE of them (see setRoles()).
    public const ROLE_BUYER = 'ROLE_BUYER';
    public const ROLE_SELLER = 'ROLE_SELLER';
    public const ROLE_ADMIN = 'ROLE_ADMIN';

    // SECURITY: lockout policy (rule 6): 5 consecutive failures lock the account for 15 minutes.
    public const MAX_FAILED_ATTEMPTS = 5;
    public const LOCKOUT_DURATION = '+15 minutes';

    // Internal auto-increment primary key. Used only for database joins, never shown in a URL.
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

    // The role of the account, stored as a JSON array, e.g. ["ROLE_BUYER"].
    // SECURITY: mass assignment / privilege escalation - no form has a "roles" field; only server code calls setRoles().
    /**
     * @var list<string>
     */
    #[ORM\Column]
    private array $roles = [];

    /**
     * Argon2id hash only, never the plain password.
     * SECURITY: sensitive data - if the database leaks, the attacker only gets slow, salted Argon2id hashes,
     * not passwords they can reuse.
     */
    #[ORM\Column]
    private string $password = '';

    // SECURITY: brute force - number of wrong passwords in a row. Reset to 0 after a successful login.
    #[ORM\Column(options: ['default' => 0])]
    private int $failedAttempts = 0;

    // SECURITY: brute force - while this date is in the future, login is refused even with the right password.
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lockedUntil = null;

    // SECURITY: account blocked by an administrator (separate from the temporary brute-force lockout above):
    // refused at login AND existing sessions are ended (LockedUserSubscriber).
    #[ORM\Column(options: ['default' => false])]
    private bool $blocked = false;

    // Marks an account as verified.
    // SECURITY: mass assignment - this flag is never a form field, so a visitor cannot verify himself.
    #[ORM\Column(options: ['default' => false])]
    private bool $isVerified = false;

    // Public name shown on products and reviews, so the email address is never displayed publicly.
    #[ORM\Column(length: 60, nullable: true)]
    #[Assert\Length(min: 2, max: 60)]
    #[Assert\Regex(pattern: '/^[\p{L}\p{N} .\'_-]+$/u', message: 'Use letters, numbers, spaces, dots, apostrophes, dashes or underscores only.')]
    private ?string $displayName = null;

    // Optional phone number (digits, spaces and an optional leading "+").
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

    // True only after the user proved the authenticator app works by typing a first valid code.
    #[ORM\Column(options: ['default' => false])]
    private bool $totpEnabled = false;

    // When the account was created.
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    // Date of the last successful login (shown in the admin panel).
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lastLoginAt = null;

    // Public shop of a seller (null for buyers and admins). Created by the server on seller approval (rule 9).
    #[ORM\OneToOne(mappedBy: 'owner')]
    private ?Shop $shop = null;

    /**
     * Runs when a new account object is created (registration, admin command, fixtures).
     * Gives it a random UUID (v4) and the creation date.
     */
    public function __construct()
    {
        $this->uuid = Uuid::v4();
        $this->createdAt = new \DateTimeImmutable();
    }

    // ----- Getters and setters. The obvious ones are not commented. -----

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

    /**
     * Sets the email (= the login).
     *
     * @param string|null $email text typed in the form (null when the field is empty)
     */
    public function setEmail(?string $email): static
    {
        // SECURITY: normalise so "Alice@x.com" and "alice@x.com" can't become two accounts.
        // null (empty form field) becomes '' and is then rejected by NotBlank as a normal validation error.
        $this->email = mb_strtolower(trim((string) $email));

        return $this;
    }

    /** Required by Symfony: the value that identifies the user. Here it is the email. */
    public function getUserIdentifier(): string
    {
        return (string) $this->email;
    }

    /**
     * Roles read by Symfony for access control (security.yaml, #[IsGranted], voters).
     * ROLE_USER is always added, so "is logged in" can be tested with one role.
     *
     * @return list<string>
     */
    public function getRoles(): array
    {
        $roles = $this->roles;
        $roles[] = 'ROLE_USER';

        return array_values(array_unique($roles));
    }

    /**
     * Sets the single role of the account. Called only by server code (registration, admin role change,
     * seller application approved), never filled from a form field.
     *
     * @param list<string> $roles
     */
    public function setRoles(array $roles): static
    {
        // SECURITY (rule 4): roles are EXCLUSIVE. An account is exactly one of BUYER, SELLER or ADMIN; anything else
        // (no role, two roles, an unknown role) is refused here, whoever the caller is.
        $roles = array_values(array_unique($roles));
        if (1 !== count($roles) || !in_array($roles[0], [self::ROLE_BUYER, self::ROLE_SELLER, self::ROLE_ADMIN], true)) {
            throw new \InvalidArgumentException('A user must have exactly one role: buyer, seller or admin.');
        }
        $this->roles = $roles;

        return $this;
    }

    /** Returns the password HASH. Symfony compares it with the typed password at login. */
    public function getPassword(): string
    {
        return $this->password;
    }

    /**
     * Stores the password. The caller must give the HASH (made by UserPasswordHasher), never the plain text.
     */
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

    /** True when an admin blocked the account. */
    public function isBlocked(): bool
    {
        return $this->blocked;
    }

    /** Admin action: blocks the account until an admin unblocks it. */
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
        // One more wrong password.
        ++$this->failedAttempts;

        if ($this->failedAttempts >= self::MAX_FAILED_ATTEMPTS) {
            // SECURITY: lock the account; counter restarts so the next lock needs 5 new failures.
            $this->lockedUntil = new \DateTimeImmutable(self::LOCKOUT_DURATION);
            $this->failedAttempts = 0;
        }
    }

    /** Clears the failure counter and the temporary lock (after a good login or an admin unlock). */
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

    /** Sets the public name. Spaces around it are removed and an empty text is stored as null. */
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

    /** Sets the phone number. Spaces around it are removed and an empty text is stored as null. */
    public function setPhone(?string $phone): static
    {
        $phone = null !== $phone ? trim($phone) : null;
        $this->phone = '' === $phone ? null : $phone;

        return $this;
    }

    /** Returns the encrypted IBAN (unreadable text). Only FieldEncryptor can turn it back into the real IBAN. */
    public function getIbanEncrypted(): ?string
    {
        return $this->ibanEncrypted;
    }

    /**
     * Saves the seller's IBAN. Seller only (profile page).
     *
     * @param string $ciphertext output of FieldEncryptor::encrypt(), never the clear IBAN
     */
    public function setEncryptedIban(string $ciphertext, string $last4): static
    {
        $this->ibanEncrypted = $ciphertext;
        $this->ibanLast4 = $last4;

        return $this;
    }

    /** Deletes the saved IBAN (both the encrypted value and the last 4 characters). */
    public function removeIban(): void
    {
        $this->ibanEncrypted = null;
        $this->ibanLast4 = null;
    }

    /**
     * SECURITY: sensitive data - what the page shows is only "•••• •••• •••• 1234".
     * The full IBAN is never decrypted just to display it.
     */
    public function getMaskedIban(): ?string
    {
        return null !== $this->ibanLast4 ? '•••• •••• •••• '.$this->ibanLast4 : null;
    }

    /** Used by scheb/2fa: must the login ask this user for a 6-digit code after the password? */
    public function isTotpAuthenticationEnabled(): bool
    {
        return $this->totpEnabled && null !== $this->totpSecret;
    }

    /** Used by scheb/2fa: the account name shown inside the authenticator app. */
    public function getTotpAuthenticationUsername(): string
    {
        return (string) $this->email;
    }

    /** Used by scheb/2fa: the settings needed to check a code (secret, algorithm, period, number of digits). */
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

    /** Switches 2FA on. Called only after the user typed a first valid code. */
    public function enableTotp(): void
    {
        $this->totpEnabled = true;
    }

    /** Switches 2FA off (by the user in the security page, or by an admin reset). */
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

    public function getLastLoginAt(): ?\DateTimeImmutable
    {
        return $this->lastLoginAt;
    }

    /** Remembers the date of this login. Called after each successful login. */
    public function recordLogin(): void
    {
        $this->lastLoginAt = new \DateTimeImmutable();
    }

    /** Fixtures only. */
    public function backdate(\DateTimeImmutable $createdAt): void
    {
        $this->createdAt = $createdAt;
    }

    public function getShop(): ?Shop
    {
        return $this->shop;
    }

    public function setShop(?Shop $shop): void
    {
        $this->shop = $shop;
    }

    // ----- Role helpers: short tests used by controllers, voters and templates. -----

    public function isBuyer(): bool
    {
        return in_array(self::ROLE_BUYER, $this->roles, true);
    }

    public function isSeller(): bool
    {
        return in_array(self::ROLE_SELLER, $this->roles, true);
    }

    public function isAdmin(): bool
    {
        return in_array(self::ROLE_ADMIN, $this->roles, true);
    }

    /**
     * Ensure the session doesn't contain the actual password hash.
     * PHP calls this method when Symfony saves the logged-in user in the session.
     */
    public function __serialize(): array
    {
        // Turn the object into an array. Private properties get keys like "\0App\Entity\User\0password".
        $data = (array) $this;
        // SECURITY: store a CRC of the hash in the session instead of the hash itself;
        // a password change still invalidates other sessions.
        $data["\0".self::class."\0password"] = hash('crc32c', (string) $this->password);
        // SECURITY: the TOTP secret never lands in session files; the user is reloaded from the DB on each request.
        $data["\0".self::class."\0totpSecret"] = null;
        $data["\0".self::class."\0ibanEncrypted"] = null;
        // The shop is reloaded with the user on each request; it doesn't need to live in the session.
        $data["\0".self::class."\0shop"] = null;

        return $data;
    }

    /** Required by UserInterface. Nothing to do: the plain password is never kept on the object. */
    #[\Deprecated]
    public function eraseCredentials(): void
    {
        // No plain-text credentials are stored on the entity.
    }
}
