<?php

/**
 * FILE: src/Entity/SellerApplication.php
 * WHAT: the SellerApplication entity = a buyer asking to become a seller.
 * ROLE IN THE PROJECT: the only way for a buyer account to get ROLE_SELLER after registration: an admin must approve it.
 * USED BY: Buyer\SellerApplicationController (buyer sends the request), Admin\ApplicationController (admin
 *   approves or rejects), SellerApplicationVoter.
 */

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
    // The three possible states of an application.
    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';
    public const STATUSES = [self::STATUS_PENDING, self::STATUS_APPROVED, self::STATUS_REJECTED];

    // Internal auto-increment primary key.
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    // SECURITY (rule 4): public identifier in admin URLs.
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $uuid;

    // The buyer who applies. Set by the server from the logged-in user.
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $applicant;

    // Wanted shop name (restricted character set).
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

    // SECURITY: validation - current state, always one of STATUSES.
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

    // Date of the admin decision (null while pending).
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $reviewedAt = null;

    // Date the application was sent.
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    /**
     * @param User $applicant the logged-in buyer who applies
     */
    public function __construct(User $applicant)
    {
        $this->uuid = Uuid::v4();
        $this->applicant = $applicant;
        $this->createdAt = new \DateTimeImmutable();
    }

    /**
     * Admin accepts the application.
     *
     * @param User $admin the logged-in admin
     */
    public function approve(User $admin): void
    {
        $this->decide($admin, self::STATUS_APPROVED, null);
    }

    /**
     * Admin refuses the application. A reason is required and is cut to 500 characters.
     *
     * @param User   $admin  the logged-in admin
     * @param string $reason explanation shown to the applicant
     */
    public function reject(User $admin, string $reason): void
    {
        $reason = trim($reason);
        if ('' === $reason) {
            throw new \DomainException('A reason is required to reject an application.');
        }
        $this->decide($admin, self::STATUS_REJECTED, mb_substr($reason, 0, 500));
    }

    /** Common code of approve() and reject(): checks the state, then records the decision. */
    private function decide(User $admin, string $status, ?string $reason): void
    {
        // SECURITY: a decision is final; an approved application can't be "re-approved" or flipped.
        if (self::STATUS_PENDING !== $this->status) {
            throw new \DomainException('This application has already been reviewed.');
        }
        // Record the decision, who took it and when.
        $this->status = $status;
        $this->reason = $reason;
        $this->reviewedBy = $admin;
        $this->reviewedAt = new \DateTimeImmutable();
    }

    // ----- Getters and setters. Only shopName and description have setters (the two form fields). -----

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

    /** True while no admin has decided yet. */
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
