<?php

/**
 * FILE: src/Entity/AuditLog.php
 * WHAT: the AuditLog entity = one line of the security journal (one row of the `audit_log` table).
 * ROLE IN THE PROJECT: keeps a trace of logins, failures, lockouts, role changes, orders and blocked attacks,
 *   so an admin can investigate what happened.
 * USED BY: AuditLogger writes the lines; the admin "Audit log" and "Security" pages read them (admin only);
 *   each user sees their own login history on the security settings page.
 */

namespace App\Entity;

use App\Repository\AuditLogRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Append-only security journal (rule 14). Rows are written by App\Service\AuditLogger and never updated;
 * there is no edit/delete feature anywhere in the application, admins can only read them.
 */
#[ORM\Entity(repositoryClass: AuditLogRepository::class, readOnly: true)]
#[ORM\Index(name: 'idx_audit_action', columns: ['action'])]
#[ORM\Index(name: 'idx_audit_created', columns: ['created_at'])]
class AuditLog
{
    // Names of every event that can be logged. Constants avoid typing mistakes in the action name.
    // Authentication
    public const LOGIN_SUCCESS = 'login.success';
    public const LOGIN_FAILURE = 'login.failure';
    public const LOGIN_BLOCKED = 'login.blocked';
    public const ACCOUNT_LOCKED = 'account.locked';
    public const LOGOUT = 'logout';
    public const REGISTER = 'user.register';
    public const PASSWORD_RESET_REQUESTED = 'password.reset_requested';
    public const PASSWORD_RESET_DONE = 'password.reset_done';
    public const PASSWORD_RESET_INVALID = 'password.reset_invalid_token';
    public const PASSWORD_CHANGED = 'password.changed';
    public const TWO_FA_ENABLED = '2fa.enabled';
    public const TWO_FA_DISABLED = '2fa.disabled';
    public const TWO_FA_SUCCESS = '2fa.success';
    public const TWO_FA_FAILURE = '2fa.failure';
    public const RATE_LIMITED = 'rate_limited';
    // Admin
    public const ADMIN_CREATED = 'admin.created';
    public const ROLE_CHANGED = 'user.role_changed';
    public const USER_LOCKED = 'user.locked';
    public const USER_UNLOCKED = 'user.unlocked';
    public const USER_2FA_RESET = 'user.2fa_reset';
    public const USER_PASSWORD_RESET_FORCED = 'user.password_reset_forced';
    public const APPLICATION_SUBMITTED = 'seller_application.submitted';
    public const APPLICATION_APPROVED = 'seller_application.approved';
    public const APPLICATION_REJECTED = 'seller_application.rejected';
    public const CATEGORY_CREATED = 'category.created';
    public const CATEGORY_UPDATED = 'category.updated';
    public const CATEGORY_DELETED = 'category.deleted';
    public const AUDIT_EXPORTED = 'audit.exported';
    // Business
    public const PRODUCT_CREATED = 'product.created';
    public const PRODUCT_UPDATED = 'product.updated';
    public const PRODUCT_DELETED = 'product.deleted';
    public const PRODUCT_DEACTIVATED = 'product.deactivated';
    public const PRODUCT_REACTIVATED = 'product.reactivated';
    public const ORDER_PLACED = 'order.placed';
    public const ORDER_STATUS_CHANGED = 'order.status_changed';
    public const ORDER_CANCELLED = 'order.cancelled';
    public const ORDER_REFUNDED = 'order.refunded';
    // Cash on delivery
    public const CASH_ORDER_PLACED = 'order.cash_placed';
    public const CASH_COLLECTED = 'order.cash_collected';
    public const CASH_REFUSED = 'order.cash_refused';
    public const CASH_ORDER_BLOCKED = 'order.cash_blocked';
    public const REVIEW_POSTED = 'review.posted';
    public const REVIEW_DELETED = 'review.deleted';
    public const REVIEW_UPDATED = 'review.updated';
    public const REVIEW_REPLIED = 'review.replied';
    public const REVIEW_REPLY_DELETED = 'review.reply_deleted';
    public const SHOP_UPDATED = 'shop.updated';
    public const ADDRESS_CREATED = 'address.created';
    public const ADDRESS_UPDATED = 'address.updated';
    public const ADDRESS_DELETED = 'address.deleted';
    public const PROFILE_UPDATED = 'profile.updated';
    public const IBAN_UPDATED = 'profile.iban_updated';
    // Attacks blocked
    public const UPLOAD_REJECTED = 'upload.rejected';
    public const SSRF_BLOCKED = 'ssrf.blocked';
    public const ACCESS_DENIED = 'access.denied';

    /** Actions shown in the admin "Security" panel. */
    public const SECURITY_EVENTS = [
        self::LOGIN_FAILURE, self::LOGIN_BLOCKED, self::ACCOUNT_LOCKED, self::TWO_FA_FAILURE,
        self::RATE_LIMITED, self::UPLOAD_REJECTED, self::SSRF_BLOCKED, self::PASSWORD_RESET_INVALID,
        self::ACCESS_DENIED, self::CASH_ORDER_BLOCKED,
    ];

    // Internal auto-increment primary key.
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    // SECURITY: SET NULL keeps the history when a user is deleted (logs must survive the account).
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $user = null;

    // Name of the event (one of the constants above), max 64 characters.
    #[ORM\Column(length: 64)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 64)]
    private string $action;

    // Extra information about the event, stored as JSON (for example the order number or the reason).
    /** @var array<string, mixed> */
    #[ORM\Column(type: Types::JSON)]
    private array $details = [];

    // IP address of the client (45 characters = longest IPv6 address).
    #[ORM\Column(length: 45, nullable: true)]
    private ?string $ip = null;

    // Browser identification sent by the client (User-Agent header).
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $userAgent = null;

    // Date and time of the event.
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    /**
     * Builds one log line. Called only by AuditLogger.
     *
     * @param string      $action    event name (constant of this class)
     * @param User|null   $user      the account concerned, if any
     * @param string|null $ip        client IP address
     * @param string|null $userAgent client User-Agent header
     * @param array<string, mixed> $details
     */
    public function __construct(string $action, array $details = [], ?User $user = null, ?string $ip = null, ?string $userAgent = null)
    {
        // Cut the values to the column sizes so a very long input can never make the INSERT fail.
        $this->action = mb_substr($action, 0, 64);
        $this->details = $details;
        $this->user = $user;
        $this->ip = null !== $ip ? mb_substr($ip, 0, 45) : null;
        // SECURITY: attacker-controlled header, truncated to the column size (and auto-escaped when displayed).
        $this->userAgent = null !== $userAgent ? mb_substr($userAgent, 0, 255) : null;
        $this->createdAt = new \DateTimeImmutable();
    }

    // ----- Getters only. There is no setter: a log line must never be changed after it is written. -----

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function getAction(): string
    {
        return $this->action;
    }

    /** @return array<string, mixed> */
    public function getDetails(): array
    {
        return $this->details;
    }

    public function getIp(): ?string
    {
        return $this->ip;
    }

    public function getUserAgent(): ?string
    {
        return $this->userAgent;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
