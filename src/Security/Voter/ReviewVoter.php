<?php

/**
 * FILE: src/Security/Voter/ReviewVoter.php
 * WHAT: the voter that answers "may this user write, edit, delete or answer THIS review?".
 * ROLE IN THE PROJECT: access control on reviews; also stops fake reviews (only real customers can review).
 * USED BY: ReviewController and Buyer\ReviewController (buyer), Seller\ReviewController (seller),
 *   Admin\ModerationController (admin), and the product page template to show or hide the review form.
 */

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
    // Names of the permissions this voter understands (explained in the class comment above).
    public const CREATE = 'REVIEW_CREATE';
    public const EDIT = 'REVIEW_EDIT';
    public const DELETE = 'REVIEW_DELETE';
    public const REPLY = 'REVIEW_REPLY';
    public const REPLY_DELETE = 'REVIEW_REPLY_DELETE';

    /**
     * @param ReviewRepository $reviews used to check "did the buyer buy it?" and "did the buyer already review it?"
     */
    public function __construct(private readonly ReviewRepository $reviews)
    {
    }

    /** Says if this voter handles the question (each permission goes with one type of object). */
    protected function supports(string $attribute, mixed $subject): bool
    {
        return (self::CREATE === $attribute && $subject instanceof Product)
            || (in_array($attribute, [self::EDIT, self::DELETE, self::REPLY], true) && $subject instanceof Review)
            || (self::REPLY_DELETE === $attribute && $subject instanceof ReviewReply);
    }

    /**
     * The decision itself.
     *
     * @return bool true = allowed, false = denied
     */
    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();
        // Nobody logged in: always refused.
        if (!$user instanceof User) {
            return false;
        }

        // --- Deleting a seller reply: admin only ---
        if ($subject instanceof ReviewReply) {
            return $user->isAdmin();
        }

        // --- Editing / deleting / answering an existing review ---
        // SECURITY: IDOR - is the logged-in user the author of this review?
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

        // --- Creating a review on a product ---
        // SECURITY: access control - three conditions: be a buyer, have really bought the product, not reviewed it yet.
        /** @var Product $subject */
        return $user->isBuyer()
            && $this->reviews->hasPurchased($user, $subject)
            && !$this->reviews->hasReviewed($user, $subject);
    }
}
