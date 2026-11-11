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
 * ORDER_VIEW (Order):        the buyer of the order, a seller who has items in it (controllers then show ONLY that
 *                            seller's items + shipping name/address, never the buyer's email/phone/payment), or an admin.
 * ORDER_CANCEL (Order):      the buyer of the order, only while it is "paid" (nothing shipped yet).
 * ORDER_ADMIN_CANCEL (Order): admin, same condition.
 * ORDER_REFUND (Order):      admin only, order paid/shipped/delivered.
 * ORDER_ITEM_UPDATE_STATUS (OrderItem): the seller of that item, or an admin; never on a cancelled/refunded item.
 *
 * @extends Voter<string, Order|OrderItem>
 */
final class OrderVoter extends Voter
{
    public const VIEW = 'ORDER_VIEW';
    public const CANCEL = 'ORDER_CANCEL';
    public const ADMIN_CANCEL = 'ORDER_ADMIN_CANCEL';
    public const REFUND = 'ORDER_REFUND';
    public const ITEM_UPDATE_STATUS = 'ORDER_ITEM_UPDATE_STATUS';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return (in_array($attribute, [self::VIEW, self::CANCEL, self::ADMIN_CANCEL, self::REFUND], true) && $subject instanceof Order)
            || (self::ITEM_UPDATE_STATUS === $attribute && $subject instanceof OrderItem);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User) {
            return false;
        }

        if ($subject instanceof OrderItem) {
            if (null === $subject->getNextStatus()) {
                return false;
            }

            // SECURITY: a seller can only move THEIR OWN items forward; admins can act on any item.
            return $user->isAdmin() || ($user->isSeller() && $subject->getSeller()->getId() === $user->getId());
        }

        /** @var Order $subject */
        $isBuyer = $user->isBuyer() && $subject->getBuyer()->getId() === $user->getId();

        return match ($attribute) {
            self::VIEW => $user->isAdmin() || $isBuyer || ($user->isSeller() && $subject->hasSeller($user)),
            self::CANCEL => $isBuyer && $subject->isCancellable(),
            self::ADMIN_CANCEL => $user->isAdmin() && $subject->isCancellable(),
            self::REFUND => $user->isAdmin() && $subject->isRefundable(),
            default => false,
        };
    }
}
