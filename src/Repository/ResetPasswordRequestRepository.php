<?php

/**
 * FILE: src/Repository/ResetPasswordRequestRepository.php
 * WHAT: repository of the password reset requests.
 * ROLE IN THE PROJECT: required by symfonycasts/reset-password-bundle; the trait contains the real queries
 *   (find a request by selector, delete expired requests...).
 * USED BY: the bundle, called from ResetPasswordController (visitor who forgot their password).
 */

namespace App\Repository;

use App\Entity\ResetPasswordRequest;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use SymfonyCasts\Bundle\ResetPassword\Model\ResetPasswordRequestInterface;
use SymfonyCasts\Bundle\ResetPassword\Persistence\Repository\ResetPasswordRequestRepositoryTrait;
use SymfonyCasts\Bundle\ResetPassword\Persistence\ResetPasswordRequestRepositoryInterface;

/**
 * @extends ServiceEntityRepository<ResetPasswordRequest>
 */
class ResetPasswordRequestRepository extends ServiceEntityRepository implements ResetPasswordRequestRepositoryInterface
{
    // Trait from the bundle with the ready-made queries.
    use ResetPasswordRequestRepositoryTrait;

    /** Tells Doctrine that this repository works on the ResetPasswordRequest entity. */
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ResetPasswordRequest::class);
    }

    /**
     * Called by the bundle to build a new request row. The bundle saves it afterwards.
     *
     * @param User $user
     */
    public function createResetPasswordRequest(object $user, \DateTimeInterface $expiresAt, string $selector, string $hashedToken): ResetPasswordRequestInterface
    {
        return new ResetPasswordRequest($user, $expiresAt, $selector, $hashedToken);
    }
}
