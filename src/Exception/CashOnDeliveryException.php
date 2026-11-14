<?php

namespace App\Exception;

/**
 * Cash on delivery refused by an anti-abuse rule (CashOnDeliveryPolicy). The message is safe to show to the buyer;
 * the reason is a fixed keyword written to the audit log.
 */
final class CashOnDeliveryException extends CheckoutException
{
    public const REASON_MAX_TOTAL = 'max_total';
    public const REASON_MAX_PENDING = 'max_pending';
    public const REASON_MULTIPLE_SELLERS = 'multiple_sellers';
    public const REASON_RATE_LIMIT = 'rate_limit';

    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }
}
