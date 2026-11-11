<?php

namespace App\Entity;

use App\Repository\NewsletterSubscriberRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Newsletter sign-up. Data minimisation (GDPR): only the email and the date of consent are stored, no IP address.
 * The signup endpoint is CSRF-protected and rate limited per IP (NewsletterController).
 */
#[ORM\Entity(repositoryClass: NewsletterSubscriberRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_newsletter_email', columns: ['email'])]
class NewsletterSubscriber
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 180)]
    #[Assert\NotBlank]
    #[Assert\Email(mode: Assert\Email::VALIDATION_MODE_STRICT)]
    #[Assert\Length(max: 180)]
    private string $email;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(string $email)
    {
        $this->email = mb_strtolower(trim($email));
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
