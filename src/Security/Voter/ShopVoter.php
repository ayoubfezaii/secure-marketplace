<?php

/**
 * FILE: src/Security/Voter/ShopVoter.php
 * WHAT: the voter that answers "may this user edit THIS shop?".
 * ROLE IN THE PROJECT: access control on shop profiles (defence against IDOR).
 * USED BY: Seller\ShopController (seller).
 */

namespace App\Security\Voter;

use App\Entity\Shop;
use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * SHOP_EDIT: only the seller who owns the shop (rule 4). Admins don't edit shop profiles.
 *
 * @extends Voter<string, Shop>
 */
final class ShopVoter extends Voter
{
    // Name of the permission this voter understands.
    public const EDIT = 'SHOP_EDIT';

    /** Says if this voter handles the question: SHOP_EDIT on a Shop object. */
    protected function supports(string $attribute, mixed $subject): bool
    {
        return self::EDIT === $attribute && $subject instanceof Shop;
    }

    /**
     * The decision itself.
     *
     * @return bool true = allowed, false = denied
     */
    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();

        // SECURITY: IDOR - allowed only when the logged-in user is a seller AND is the owner of this shop.
        /** @var Shop $subject */
        return $user instanceof User && $user->isSeller() && $subject->getOwner()->getId() === $user->getId();
    }
}
