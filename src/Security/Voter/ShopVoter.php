<?php

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
    public const EDIT = 'SHOP_EDIT';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return self::EDIT === $attribute && $subject instanceof Shop;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();

        /** @var Shop $subject */
        return $user instanceof User && $user->isSeller() && $subject->getOwner()->getId() === $user->getId();
    }
}
