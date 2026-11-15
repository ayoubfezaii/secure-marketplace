<?php

/**
 * FILE: src/Service/CashOnDeliveryPolicy.php
 * WHAT: service with the rules that decide if an order may be paid in cash at delivery.
 * ROLE IN THE PROJECT: business-logic protection (abuse of a feature), checked on the server.
 * USED BY: CheckoutController (early check at the payment step) and CheckoutService (real check inside the
 *   transaction). Buyer flow.
 */

namespace App\Service;

use App\Entity\AuditLog;
use App\Entity\User;
use App\Exception\CashOnDeliveryException;
use App\Repository\OrderRepository;

/**
 * Anti-abuse rules for cash on delivery.
 *
 * Why: a cash order reserves stock and makes a seller ship a parcel without any payment. Without limits, one
 * account could block the whole stock of a shop with orders it never intends to pay (denial of inventory), or
 * order expensive goods and refuse them at the door.
 *
 * SECURITY: every rule is checked on the SERVER, with figures the server computed itself:
 *  - the total comes from the database prices (CheckoutService, locked rows), never from the request (rule 10);
 *  - the number of pending cash orders is counted in the database for the logged-in buyer (rule 4);
 *  - the sellers are those of the locked product rows.
 * The browser only hides the option for comfort; hiding nothing would change nothing.
 */
final class CashOnDeliveryPolicy
{
    /** Highest order total payable in cash, in dinars (DT). Change it here only. */
    public const MAX_ORDER_TOTAL = '500.000';

    /** Cash orders a buyer may have "pending" (not collected, not cancelled) at the same time. */
    public const MAX_PENDING_ORDERS = 2;

    /**
     * @param OrderRepository $orders to count the buyer's pending cash orders
     * @param AuditLogger     $audit  writes blocked attempts into the audit log
     */
    public function __construct(
        private readonly OrderRepository $orders,
        private readonly AuditLogger $audit,
    ) {
    }

    /**
     * Checks the three rules. Returns nothing when the order is allowed, throws an exception otherwise.
     *
     * @param User      $buyer     the logged-in buyer
     * @param string    $total     order total computed by the server from database prices
     * @param list<int> $sellerIds ids of the sellers of the products in the order
     *
     * @throws CashOnDeliveryException with a message safe to show to the buyer
     */
    public function assertAllowed(User $buyer, string $total, array $sellerIds): void
    {
        // SECURITY: one parcel, one seller. The seller who confirms "cash collected" or "refused at delivery" must
        // be the only one concerned by the order, so nobody can act on another seller's items.
        if (count(array_unique($sellerIds)) > 1) {
            throw new CashOnDeliveryException(CashOnDeliveryException::REASON_MULTIPLE_SELLERS, 'Cash on delivery is only available when all the items come from the same shop. Please pay by card or order from one shop at a time.');
        }
        // SECURITY: bcmath comparison on decimal strings (no float rounding trick around the limit).
        if (bccomp($total, self::MAX_ORDER_TOTAL, Money::SCALE) > 0) {
            throw new CashOnDeliveryException(CashOnDeliveryException::REASON_MAX_TOTAL, sprintf('Cash on delivery is limited to orders up to %s. Please pay by card or reduce your cart.', Money::format(self::MAX_ORDER_TOTAL)));
        }
        // Rule 3: not too many unpaid cash orders at the same time (counted in the database).
        if ($this->orders->countPendingCashOrders($buyer) >= self::MAX_PENDING_ORDERS) {
            throw new CashOnDeliveryException(CashOnDeliveryException::REASON_MAX_PENDING, sprintf('You already have %d cash-on-delivery orders waiting to be paid. Please pay by card, or wait until one of them is delivered.', self::MAX_PENDING_ORDERS));
        }
    }

    /**
     *
     * @param User                    $buyer the buyer who was blocked
     * @param CashOnDeliveryException $e     the refusal (contains the reason code)
     * @param string|null             $total order total, when known
     * Rule 14: every blocked attempt is written to the audit log (and shown in the admin security panel).
     */
    public function logBlocked(User $buyer, CashOnDeliveryException $e, ?string $total = null): void
    {
        $this->audit->log(AuditLog::CASH_ORDER_BLOCKED, array_filter(['reason' => $e->reason, 'total' => $total]), $buyer);
    }
}
