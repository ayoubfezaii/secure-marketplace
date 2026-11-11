<?php

namespace App\Entity;

use App\Repository\SellerApplicationRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A buyer's request to become a seller, reviewed by an administrator.
 *
 * SECURITY (rule 9): applicant, status, reviewer and dates are set by the server only (constructor + approve() /
 * reject()). The form exposes only shopName and description. State machine: pending -> approved | rejected, once.
 */
#[ORM\Entity(repositoryClass: SellerApplicationRepository::class)]
#[ORM\Index(name: 'idx_application_status', columns: ['status'])]
class SellerApplication
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';
    public const STATUSES = [self::STATUS_PENDING, self::STATUS_APPROVED, self::STATUS_REJECTED];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    // SECURITY (rule 4): public identifier in admin URLs.
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $uuid;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $applicant;

    #[ORM\Column(length: 80)]
    #[Assert\NotBlank(message: 'Please choose a shop name.')]
    #[Assert\Length(min: 3, max: 80)]
    #[Assert\Regex(pattern: '/^[\p{L}\p{N} &.\'_-]+$/u', message: 'Use letters, numbers, spaces and & . \' _ - only.')]
    private string $shopName = '';

    // Plain text, auto-escaped on display (rule 2).
    #[ORM\Column(type: Types::TEXT)]
    #[Assert\NotBlank(message: 'Tell us about what you want to sell.')]
    #[Assert\Length(min: 30, max: 2000, minMessage: 'Please write at least {{ limit }} characters.')]
    private string $description = '';

    #[ORM\Column(length: 16)]
    #[Assert\Choice(choices: self::STATUSES)]
    private string $status = self::STATUS_PENDING;

    // Reason given by the admin (required on rejection), shown to the applicant.
    #[ORM\Column(length: 500, nullable: true)]
    #[Assert\Length(max: 500)]
    private ?string $reason = null;

    // SET NULL: the decision history survives if the admin account is removed.
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $reviewedBy = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $reviewedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(User $applicant)
    {
        $this->uuid = Uuid::v4();
        $this->applicant = $applicant;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function approve(User $admin): void
    {
        $this->decide($admin, self::STATUS_APPROVED, null);
    }

    public function reject(User $admin, string $reason): void
    {
        $reason = trim($reason);
        if ('' === $reason) {
            throw new \DomainException('A reason is required to reject an application.');
        }
        $this->decide($admin, self::STATUS_REJECTED, mb_substr($reason, 0, 500));
    }

    private function decide(User $admin, string $status, ?string $reason): void
    {
        // SECURITY: a decision is final; an approved application can't be "re-approved" or flipped.
        if (self::STATUS_PENDING !== $this->status) {
            throw new \DomainException('This application has already been reviewed.');
        }
        $this->status = $status;
        $this->reason = $reason;
        $this->reviewedBy = $admin;
        $this->reviewedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUuid(): Uuid
    {
        return $this->uuid;
    }

    public function getApplicant(): User
    {
        return $this->applicant;
    }

    public function getShopName(): string
    {
        return $this->shopName;
    }

    public function setShopName(?string $shopName): static
    {
        $this->shopName = trim((string) $shopName);

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

    public function getStatus(): string
    {
        return $this->status;
    }

    public function isPending(): bool
    {
        return self::STATUS_PENDING === $this->status;
    }

    public function getReason(): ?string
    {
        return $this->reason;
    }

    public function getReviewedBy(): ?User
    {
        return $this->reviewedBy;
    }

    public function getReviewedAt(): ?\DateTimeImmutable
    {
        return $this->reviewedAt;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /** Fixtures only. */
    public function backdate(\DateTimeImmutable $createdAt): void
    {
        $this->createdAt = $createdAt;
    }
}
