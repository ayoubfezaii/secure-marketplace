<?php

namespace App\Entity;

use App\Repository\UserRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: '`user`')]
// SECURITY: the DB unique index guarantees one account per email even under concurrent registrations (rule 15).
#[ORM\UniqueConstraint(name: 'UNIQ_IDENTIFIER_EMAIL', fields: ['email'])]
#[UniqueEntity(fields: ['email'], message: 'This email address is already registered.')]
class User implements UserInterface, PasswordAuthenticatedUserInterface
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
    private ?string $email = null;

    /**
     * @var list<string>
     */
    #[ORM\Column]
    private array $roles = [];

    /**
     * Argon2id hash only, never the plain password.
     */
    #[ORM\Column]
    private ?string $password = null;

    #[ORM\Column(options: ['default' => 0])]
    private int $failedAttempts = 0;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lockedUntil = null;

    #[ORM\Column(options: ['default' => false])]
    private bool $isVerified = false;

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

    public function getEmail(): ?string
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

    public function getPassword(): ?string
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

    public function isLocked(): bool
    {
        return null !== $this->lockedUntil && $this->lockedUntil > new \DateTimeImmutable();
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

        return $data;
    }

    #[\Deprecated]
    public function eraseCredentials(): void
    {
        // No plain-text credentials are stored on the entity.
    }
}
