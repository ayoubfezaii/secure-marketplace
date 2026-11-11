<?php

namespace App\Security\Voter;

use App\Entity\Product;
use App\Entity\Review;
use App\Entity\ReviewReply;
use App\Entity\User;
use App\Repository\ReviewRepository;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Who may write / edit / delete reviews and replies (rule 4).
 *
 * REVIEW_CREATE (Product):     a buyer who bought the product (non-cancelled order) and hasn't reviewed it yet.
 *                              Prevents fake reviews and review spam.
 * REVIEW_EDIT (Review):        its author (still a buyer).
 * REVIEW_DELETE (Review):      its author, or an admin (moderation).
 * REVIEW_REPLY (Review):       the seller of the reviewed product, once (no reply yet).
 * REVIEW_REPLY_DELETE (ReviewReply): admin only (moderation).
 *
 * @extends Voter<string, Product|Review|ReviewReply>
 */
final class ReviewVoter extends Voter
{
    public const CREATE = 'REVIEW_CREATE';
    public const EDIT = 'REVIEW_EDIT';
    public const DELETE = 'REVIEW_DELETE';
    public const REPLY = 'REVIEW_REPLY';
    public const REPLY_DELETE = 'REVIEW_REPLY_DELETE';

    public function __construct(private readonly ReviewRepository $reviews)
    {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return (self::CREATE === $attribute && $subject instanceof Product)
            || (in_array($attribute, [self::EDIT, self::DELETE, self::REPLY], true) && $subject instanceof Review)
            || (self::REPLY_DELETE === $attribute && $subject instanceof ReviewReply);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User) {
            return false;
        }

        if ($subject instanceof ReviewReply) {
            return $user->isAdmin();
        }

        if ($subject instanceof Review) {
            $isAuthor = $subject->getAuthor()->getId() === $user->getId();

            return match ($attribute) {
                self::EDIT => $isAuthor && $user->isBuyer(),
                self::DELETE => ($isAuthor && $user->isBuyer()) || $user->isAdmin(),
                // SECURITY: only the seller who owns the product, and only if there is no reply yet (one per review;
                // a unique index enforces it in the database too).
                self::REPLY => $user->isSeller() && $subject->getProduct()->getSeller()->getId() === $user->getId() && null === $subject->getReply(),
                default => false,
            };
        }

        /** @var Product $subject */
        return $user->isBuyer()
            && $this->reviews->hasPurchased($user, $subject)
            && !$this->reviews->hasReviewed($user, $subject);
    }
}
