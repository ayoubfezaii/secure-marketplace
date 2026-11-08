<?php

namespace App\Security\Voter;

use App\Entity\Product;
use App\Entity\Review;
use App\Entity\User;
use App\Repository\ReviewRepository;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Who may write / delete reviews (rule 4).
 *
 * REVIEW_CREATE (Product): a buyer who bought the product (non-cancelled order) and hasn't reviewed it yet.
 *                          Prevents fake reviews and review spam.
 * REVIEW_DELETE (Review):  the author, or an admin (moderation).
 *
 * @extends Voter<string, Product|Review>
 */
final class ReviewVoter extends Voter
{
    public const CREATE = 'REVIEW_CREATE';
    public const DELETE = 'REVIEW_DELETE';

    public function __construct(private readonly ReviewRepository $reviews)
    {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return (self::CREATE === $attribute && $subject instanceof Product)
            || (self::DELETE === $attribute && $subject instanceof Review);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User) {
            return false;
        }
        $roles = $user->getRoles();

        if ($subject instanceof Review) {
            return $subject->getAuthor()->getId() === $user->getId() || in_array(User::ROLE_ADMIN, $roles, true);
        }

        /** @var Product $subject */
        return in_array(User::ROLE_BUYER, $roles, true)
            && $this->reviews->hasPurchased($user, $subject)
            && !$this->reviews->hasReviewed($user, $subject);
    }
}
