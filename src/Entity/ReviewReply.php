<?php

namespace App\Entity;

use App\Repository\ReviewReplyRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * The seller's public answer to a review. One reply per review, enforced by a UNIQUE index (even with two
 * simultaneous submits) and by ReviewVoter::REPLY (only the seller of the reviewed product, only once).
 * Plain text, auto-escaped on display (rule 2).
 */
#[ORM\Entity(repositoryClass: ReviewReplyRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_reply_review', columns: ['review_id'])]
class ReviewReply
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $uuid;

    #[ORM\OneToOne(inversedBy: 'reply')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Review $review;

    // SECURITY (rule 9): set from the session by the controller, never from the form.
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $author;

    #[ORM\Column(type: Types::TEXT)]
    #[Assert\NotBlank(message: 'Please write a reply.')]
    #[Assert\Length(min: 2, max: 1000)]
    private string $body = '';

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(Review $review, User $author)
    {
        $this->uuid = Uuid::v4();
        $this->review = $review;
        $this->author = $author;
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

    public function getReview(): Review
    {
        return $this->review;
    }

    public function getAuthor(): User
    {
        return $this->author;
    }

    public function getBody(): string
    {
        return $this->body;
    }

    public function setBody(?string $body): static
    {
        $this->body = trim((string) $body);

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
