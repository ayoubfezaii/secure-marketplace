<?php

/**
 * FILE: src/Enum/PaymentStatus.php
 * WHAT: the list of payment states of an order (paid, pending, collected, refunded).
 * ROLE IN THE PROJECT: separates "where is the parcel" (order status) from "where is the money" (this enum).
 * USED BY: Order (state machine), revenue statistics of the seller and admin dashboards, order pages.
 */

namespace App\Enum;

/**
 * Where the money of an order is.
 *
 *  - Paid:      card order, charged at checkout (simulated).
 *  - Pending:   cash on delivery, nothing received yet.
 *  - Collected: cash on delivery, the seller confirmed the cash at delivery.
 *  - Refunded:  the money went back to the buyer (cancelled / refunded order).
 *
 * SECURITY: never set from a request. Only Order's state machine changes it (CheckoutService / OrderManager).
 */
enum PaymentStatus: string
{
    case Paid = 'paid';
    case Pending = 'pending';
    case Collected = 'collected';
    case Refunded = 'refunded';

    /** Text shown to the user for this payment state. */
    public function label(): string
    {
        return match ($this) {
            self::Paid => 'Paid',
            self::Pending => 'Pending',
            self::Collected => 'Collected',
            self::Refunded => 'Refunded',
        };
    }

    /**
     * Payment statuses that mean the money was really received: only these count as revenue.
     *
     * @return list<string>
     */
    public static function settledValues(): array
    {
        return [self::Paid->value, self::Collected->value];
    }
}
