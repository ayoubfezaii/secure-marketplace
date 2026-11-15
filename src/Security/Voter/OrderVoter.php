<?php

/**
 * FILE: src/Security/Voter/OrderVoter.php
 * WHAT: the voter that answers "may this user see / cancel / refund / update THIS order?".
 * ROLE IN THE PROJECT: access control on each order and order line (defence against IDOR).
 * USED BY: OrderController (buyer), Seller\OrderController (seller), Admin\ModerationController (admin) and the
 *   templates (is_granted) to show or hide the buttons.
 */

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
 *                            seller's items + shipping name/address, never the buyer's email/phone/card), or an admin.
 * ORDER_CANCEL (Order):      the buyer of the order, only while nothing has shipped ("paid", or "placed" for cash).
 * ORDER_ADMIN_CANCEL (Order): admin, same condition.
 * ORDER_REFUND (Order):      admin only, order paid/shipped/delivered whose money was received.
 * ORDER_CASH_COLLECT (Order): the seller of EVERY item of a delivered, unpaid cash-on-delivery order.
 * ORDER_CASH_REFUSE (Order):  the seller of EVERY item of a shipped/delivered, unpaid cash-on-delivery order.
 * ORDER_ITEM_UPDATE_STATUS (OrderItem): the seller of that item, or an admin; never on a cancelled/refunded item.
 *
 * @extends Voter<string, Order|OrderItem>
 */
final class OrderVoter extends Voter
{
    // Names of the permissions this voter understands (explained in the class comment above).
    public const VIEW = 'ORDER_VIEW';
    public const CANCEL = 'ORDER_CANCEL';
    public const ADMIN_CANCEL = 'ORDER_ADMIN_CANCEL';
    public const REFUND = 'ORDER_REFUND';
    public const CASH_COLLECT = 'ORDER_CASH_COLLECT';
    public const CASH_REFUSE = 'ORDER_CASH_REFUSE';
    public const ITEM_UPDATE_STATUS = 'ORDER_ITEM_UPDATE_STATUS';

    /**
     * Says if this voter handles the question: order permissions on an Order, item permission on an OrderItem.
     */
    protected function supports(string $attribute, mixed $subject): bool
    {
        return (in_array($attribute, [self::VIEW, self::CANCEL, self::ADMIN_CANCEL, self::REFUND, self::CASH_COLLECT, self::CASH_REFUSE], true) && $subject instanceof Order)
            || (self::ITEM_UPDATE_STATUS === $attribute && $subject instanceof OrderItem);
    }

    /**
     * The decision itself.
     *
     * @param string         $attribute the permission asked
     * @param mixed          $subject   the Order or OrderItem
     * @param TokenInterface $token     gives the logged-in user
     *
     * @return bool true = allowed, false = denied (error 403)
     */
    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();
        // Nobody logged in: always refused.
        if (!$user instanceof User) {
            return false;
        }

        // --- Question about ONE LINE of an order (change its status) ---
        if ($subject instanceof OrderItem) {
            // A finished, cancelled or refunded line has no next status: nothing can be done.
            if (null === $subject->getNextStatus()) {
                return false;
            }

            // SECURITY: a seller can only move THEIR OWN items forward; admins can act on any item.
            return $user->isAdmin() || ($user->isSeller() && $subject->getSeller()->getId() === $user->getId());
        }

        // --- Questions about a whole ORDER ---
        // SECURITY: IDOR - true only when the logged-in buyer is the buyer of this order.
        /** @var Order $subject */
        $isBuyer = $user->isBuyer() && $subject->getBuyer()->getId() === $user->getId();
        // SECURITY: the cash outcome changes the whole order (payment status, or cancellation + stock), so it is
        // reserved to the seller who owns EVERY item. A seller with no item, or only some items, is refused; the
        // buyer can never mark their own order as paid.
        $isOnlySeller = $user->isSeller() && $subject->isSoldOnlyBy($user);

        // One rule per permission. Each rule mixes WHO the user is and the STATE of the order.
        return match ($attribute) {
            self::VIEW => $user->isAdmin() || $isBuyer || ($user->isSeller() && $subject->hasSeller($user)),
            self::CANCEL => $isBuyer && $subject->isCancellable(),
            self::ADMIN_CANCEL => $user->isAdmin() && $subject->isCancellable(),
            self::REFUND => $user->isAdmin() && $subject->isRefundable(),
            self::CASH_COLLECT => $isOnlySeller && $subject->isCashCollectable(),
            self::CASH_REFUSE => $isOnlySeller && $subject->isDeliveryRefusable(),
            default => false,
        };
    }
}
