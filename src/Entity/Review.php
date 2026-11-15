<?php

/**
 * FILE: src/Entity/Review.php
 * WHAT: the Review entity = one rating (1 to 5 stars) and comment left on a product.
 * ROLE IN THE PROJECT: user-generated text shown publicly, so it is the main example for stored XSS defence.
 * USED BY: ReviewController (a buyer who bought the product posts it), Buyer\ReviewController (edit / delete own
 *   reviews), Seller\ReviewController (seller replies), admin moderation (admin deletes), product page (everyone).
 */

namespace App\Entity;

use App\Repository\ReviewRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Product review. Plain text only: it is stored as typed and ALWAYS auto-escaped when displayed, so stored XSS
 * is impossible (rule 2). No HTML is ever interpreted, so no sanitizer is needed.
 */
#[ORM\Entity(repositoryClass: ReviewRepository::class)]
// SECURITY: one review per buyer per product, guaranteed by the database even with two simultaneous submits.
#[ORM\UniqueConstraint(name: 'uniq_review_product_author', columns: ['product_id', 'author_id'])]
class Review
{
    // Internal auto-increment primary key.
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    // SECURITY: IDOR - random UUID used in URLs and forms instead of the id.
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $uuid;

    // The product that is reviewed. CASCADE: reviews are deleted with the product.
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Product $product;

    // SECURITY: set from the session by the controller, never from the form (rule 9).
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $author;

    // SECURITY: validation - the rating must be a whole number from 1 to 5.
    #[ORM\Column(type: Types::SMALLINT)]
    #[Assert\NotNull]
    #[Assert\Range(min: 1, max: 5)]
    private int $rating = 5;

    // The comment: plain text, 3 to 1000 characters.
    #[ORM\Column(type: Types::TEXT)]
    #[Assert\NotBlank]
    #[Assert\Length(min: 3, max: 1000)]
    private string $comment = '';

    // Date of creation, and date of the last edit (null if never edited).
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    // The seller's public answer (at most one, see ReviewReply).
    #[ORM\OneToOne(mappedBy: 'review', cascade: ['remove'])]
    private ?ReviewReply $reply = null;

    /**
     * @param Product $product the reviewed product
     * @param User    $author  the logged-in buyer
     */
    public function __construct(Product $product, User $author)
    {
        $this->uuid = Uuid::v4();
        $this->product = $product;
        $this->author = $author;
        $this->createdAt = new \DateTimeImmutable();
    }

    // ----- Getters and setters. -----

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUuid(): Uuid
    {
        return $this->uuid;
    }

    public function getProduct(): Product
    {
        return $this->product;
    }

    public function getAuthor(): User
    {
        return $this->author;
    }

    public function getRating(): int
    {
        return $this->rating;
    }

    public function setRating(?int $rating): static
    {
        $this->rating = (int) $rating;

        return $this;
    }

    public function getComment(): string
    {
        return $this->comment;
    }

    public function setComment(?string $comment): static
    {
        $this->comment = trim((string) $comment);

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /** Records that the author changed the review ("edited" label). */
    public function markEdited(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getReply(): ?ReviewReply
    {
        return $this->reply;
    }

    public function setReply(?ReviewReply $reply): void
    {
        $this->reply = $reply;
    }

    /** Fixtures only. */
    public function backdate(\DateTimeImmutable $createdAt): void
    {
        $this->createdAt = $createdAt;
    }
}
