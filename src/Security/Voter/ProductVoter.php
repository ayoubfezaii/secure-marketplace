<?php

/**
 * FILE: src/Security/Voter/ProductVoter.php
 * WHAT: the voter that answers "may this user view / edit / delete / hide THIS product?".
 * ROLE IN THE PROJECT: access control on each product (defence against IDOR on /seller/products/{uuid}).
 * USED BY: CatalogController (view, everyone), Seller\ProductController (seller), Admin\ModerationController (admin).
 */

namespace App\Security\Voter;

use App\Entity\Product;
use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Object-level access control for products (rule 4, IDOR).
 *
 * VIEW:     anyone if active; otherwise only the owner or an admin.
 * EDIT:     owner seller only (an admin moderates, but never rewrites a seller's listing).
 * DELETE:   owner seller, or admin (moderation).
 * TOGGLE:   owner seller (hide/show own listing) or admin (deactivate/reactivate).
 * MODERATE: admin only (admin product list actions).
 *
 * @extends Voter<string, Product>
 */
final class ProductVoter extends Voter
{
    // Names of the permissions this voter understands (explained in the class comment above).
    public const VIEW = 'PRODUCT_VIEW';
    public const EDIT = 'PRODUCT_EDIT';
    public const DELETE = 'PRODUCT_DELETE';
    public const TOGGLE = 'PRODUCT_TOGGLE';
    public const MODERATE = 'PRODUCT_MODERATE';

    /** Says if this voter handles the question: one of the 5 product permissions, on a Product object. */
    protected function supports(string $attribute, mixed $subject): bool
    {
        return in_array($attribute, [self::VIEW, self::EDIT, self::DELETE, self::TOGGLE, self::MODERATE], true) && $subject instanceof Product;
    }

    /**
     * The decision itself.
     *
     * @param string         $attribute the permission asked
     * @param mixed          $subject   the Product
     * @param TokenInterface $token     gives the logged-in user (or no user for a visitor)
     *
     * @return bool true = allowed, false = denied
     */
    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        /** @var Product $product */
        $product = $subject;
        $user = $token->getUser();
        // False for a visitor who is not logged in.
        $isUser = $user instanceof User;
        // SECURITY: ownership compared on the database id of the logged-in user, never on a request value, and the
        // user must STILL be a seller (a demoted seller can't manage old listings).
        $isOwner = $isUser && $user->isSeller() && $product->getSeller()->getId() === $user->getId();
        $isAdmin = $isUser && $user->isAdmin();

        // One rule per permission.
        return match ($attribute) {
            self::VIEW => $product->isActive() || $isOwner || $isAdmin,
            self::EDIT => $isOwner,
            self::DELETE, self::TOGGLE => $isOwner || $isAdmin,
            self::MODERATE => $isAdmin,
            default => false,
        };
    }
}
