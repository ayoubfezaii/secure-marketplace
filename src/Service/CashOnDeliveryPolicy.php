<?php

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
    /** Highest order total payable in cash. Change it here only. */
    public const MAX_ORDER_TOTAL = '500.00';

    /** Cash orders a buyer may have "pending" (not collected, not cancelled) at the same time. */
    public const MAX_PENDING_ORDERS = 2;

    public function __construct(
        private readonly OrderRepository $orders,
        private readonly AuditLogger $audit,
    ) {
    }

    /**
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
        if (bccomp($total, self::MAX_ORDER_TOTAL, 2) > 0) {
            throw new CashOnDeliveryException(CashOnDeliveryException::REASON_MAX_TOTAL, sprintf('Cash on delivery is limited to orders up to %s €. Please pay by card or reduce your cart.', number_format((float) self::MAX_ORDER_TOTAL, 2, '.', ' ')));
        }
        if ($this->orders->countPendingCashOrders($buyer) >= self::MAX_PENDING_ORDERS) {
            throw new CashOnDeliveryException(CashOnDeliveryException::REASON_MAX_PENDING, sprintf('You already have %d cash-on-delivery orders waiting to be paid. Please pay by card, or wait until one of them is delivered.', self::MAX_PENDING_ORDERS));
        }
    }

    /**
     * Rule 14: every blocked attempt is written to the audit log (and shown in the admin security panel).
     */
    public function logBlocked(User $buyer, CashOnDeliveryException $e, ?string $total = null): void
    {
        $this->audit->log(AuditLog::CASH_ORDER_BLOCKED, array_filter(['reason' => $e->reason, 'total' => $total]), $buyer);
    }
}
