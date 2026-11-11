<?php

namespace App\Security\Voter;

use App\Entity\SellerApplication;
use App\Entity\User;
use App\Repository\SellerApplicationRepository;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Seller applications (rule 4).
 *
 * SELLER_APPLY (no subject):      a buyer without a pending application.
 * SELLER_APPLICATION_VIEW (app):  its applicant or an admin.
 * SELLER_APPLICATION_REVIEW (app): admin, only while the application is pending, never his own.
 *
 * @extends Voter<string, SellerApplication|null>
 */
final class SellerApplicationVoter extends Voter
{
    public const APPLY = 'SELLER_APPLY';
    public const VIEW = 'SELLER_APPLICATION_VIEW';
    public const REVIEW = 'SELLER_APPLICATION_REVIEW';

    public function __construct(private readonly SellerApplicationRepository $applications)
    {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return (self::APPLY === $attribute && null === $subject)
            || (in_array($attribute, [self::VIEW, self::REVIEW], true) && $subject instanceof SellerApplication);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User) {
            return false;
        }

        if (self::APPLY === $attribute) {
            // SECURITY: roles are exclusive; only buyers apply, one pending application at a time (no spam).
            return $user->isBuyer() && !$this->applications->hasPending($user);
        }

        /** @var SellerApplication $subject */
        return match ($attribute) {
            self::VIEW => $user->isAdmin() || $subject->getApplicant()->getId() === $user->getId(),
            self::REVIEW => $user->isAdmin() && $subject->isPending() && $subject->getApplicant()->getId() !== $user->getId(),
            default => false,
        };
    }
}
