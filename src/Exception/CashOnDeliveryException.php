<?php

/**
 * FILE: src/Exception/CashOnDeliveryException.php
 * WHAT: exception thrown when a cash-on-delivery order is refused by a rule.
 * ROLE IN THE PROJECT: carries a message for the buyer and a short reason code for the audit log.
 * USED BY: CashOnDeliveryPolicy throws it; CheckoutController and CheckoutService catch it (buyer flow).
 */

namespace App\Exception;

/**
 * Cash on delivery refused by an anti-abuse rule (CashOnDeliveryPolicy). The message is safe to show to the buyer;
 * the reason is a fixed keyword written to the audit log.
 */
final class CashOnDeliveryException extends CheckoutException
{
    // The possible reason codes.
    public const REASON_MAX_TOTAL = 'max_total';
    public const REASON_MAX_PENDING = 'max_pending';
    public const REASON_MULTIPLE_SELLERS = 'multiple_sellers';
    public const REASON_RATE_LIMIT = 'rate_limit';

    /**
     * @param string $reason  one of the REASON_* constants (for the audit log)
     * @param string $message text shown to the buyer
     */
    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }
}
