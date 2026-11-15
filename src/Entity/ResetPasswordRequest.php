<?php

/**
 * FILE: src/Entity/ResetPasswordRequest.php
 * WHAT: the ResetPasswordRequest entity = one "forgot password" request waiting to be used.
 * ROLE IN THE PROJECT: storage needed by symfonycasts/reset-password-bundle.
 * USED BY: ResetPasswordController (any visitor who forgot their password).
 */

namespace App\Entity;

use App\Repository\ResetPasswordRequestRepository;
use Doctrine\ORM\Mapping as ORM;
use SymfonyCasts\Bundle\ResetPassword\Model\ResetPasswordRequestInterface;
use SymfonyCasts\Bundle\ResetPassword\Model\ResetPasswordRequestTrait;

/**
 * SECURITY: only a selector and a HASH of the reset token are stored (via the trait). A DB leak doesn't reveal
 * usable reset links, and the selector/verifier split prevents timing attacks on the lookup (rule 5).
 */
#[ORM\Entity(repositoryClass: ResetPasswordRequestRepository::class)]
class ResetPasswordRequest implements ResetPasswordRequestInterface
{
    // Trait from the bundle: adds the columns selector, hashedToken, requestedAt and expiresAt.
    use ResetPasswordRequestTrait;

    // Internal auto-increment primary key.
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    // SECURITY: requests are deleted with the user, no orphan tokens.
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    /**
     * Called by the bundle (through ResetPasswordRequestRepository) when a reset link is created.
     *
     * @param User               $user        the account that asked for the reset
     * @param \DateTimeInterface $expiresAt   end of validity of the link
     * @param string             $selector    public part of the token, used to find the row
     * @param string             $hashedToken hash of the secret part of the token
     */
    public function __construct(User $user, \DateTimeInterface $expiresAt, string $selector, string $hashedToken)
    {
        $this->user = $user;
        $this->initialize($expiresAt, $selector, $hashedToken);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }
}
