<?php

namespace App\Security\Voter;

use App\Entity\Order;
use App\Entity\OrderItem;
use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Object-level access control for orders (rule 4, IDOR).
 *
 * ORDER_VIEW (Order):           the buyer of the order, a seller who has items in it (the template shows only
 *                               their own items), or an admin.
 * ORDER_ITEM_SHIP (OrderItem):  only the seller of that item.
 *
 * @extends Voter<string, Order|OrderItem>
 */
final class OrderVoter extends Voter
{
    public const VIEW = 'ORDER_VIEW';
    public const ITEM_UPDATE_STATUS = 'ORDER_ITEM_UPDATE_STATUS';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return (self::VIEW === $attribute && $subject instanceof Order)
            || (self::ITEM_UPDATE_STATUS === $attribute && $subject instanceof OrderItem);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User) {
            return false;
        }
        $roles = $user->getRoles();

        if ($subject instanceof OrderItem) {
            // SECURITY: a seller can only move THEIR OWN items forward.
            return in_array(User::ROLE_SELLER, $roles, true) && $subject->getSeller()->getId() === $user->getId();
        }

        /** @var Order $subject */
        if (in_array(User::ROLE_ADMIN, $roles, true)) {
            return true;
        }
        if ($subject->getBuyer()->getId() === $user->getId()) {
            return true;
        }

        return in_array(User::ROLE_SELLER, $roles, true) && $subject->hasSeller($user);
    }
}
