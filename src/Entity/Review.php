<?php

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
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $uuid;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Product $product;

    // SECURITY: set from the session by the controller, never from the form (rule 9).
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $author;

    #[ORM\Column(type: Types::SMALLINT)]
    #[Assert\NotNull]
    #[Assert\Range(min: 1, max: 5)]
    private int $rating = 5;

    #[ORM\Column(type: Types::TEXT)]
    #[Assert\NotBlank]
    #[Assert\Length(min: 3, max: 1000)]
    private string $comment = '';

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(Product $product, User $author)
    {
        $this->uuid = Uuid::v4();
        $this->product = $product;
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
}
