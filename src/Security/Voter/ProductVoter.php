<?php

namespace App\Security\Voter;

use App\Entity\Product;
use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Object-level access control for products (rule 4, IDOR).
 *
 * VIEW:   anyone if active; otherwise only the owner or an admin.
 * EDIT:   owner only (an admin moderates, but never rewrites a seller's listing).
 * DELETE: owner only.
 * TOGGLE: owner (hide/show own listing) or admin (moderation).
 *
 * @extends Voter<string, Product>
 */
final class ProductVoter extends Voter
{
    public const VIEW = 'PRODUCT_VIEW';
    public const EDIT = 'PRODUCT_EDIT';
    public const DELETE = 'PRODUCT_DELETE';
    public const TOGGLE = 'PRODUCT_TOGGLE';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return in_array($attribute, [self::VIEW, self::EDIT, self::DELETE, self::TOGGLE], true) && $subject instanceof Product;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        /** @var Product $product */
        $product = $subject;
        $user = $token->getUser();
        $isUser = $user instanceof User;
        // SECURITY: ownership compared on the database id of the logged-in user, never on a request value.
        $isOwner = $isUser && $product->getSeller()->getId() === $user->getId() && in_array(User::ROLE_SELLER, $user->getRoles(), true);
        $isAdmin = $isUser && in_array(User::ROLE_ADMIN, $user->getRoles(), true);

        return match ($attribute) {
            self::VIEW => $product->isActive() || $isOwner || $isAdmin,
            self::EDIT, self::DELETE => $isOwner,
            self::TOGGLE => $isOwner || $isAdmin,
            default => false,
        };
    }
}
