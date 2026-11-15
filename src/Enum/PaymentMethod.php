<?php

/**
 * FILE: src/Enum/PaymentMethod.php
 * WHAT: the list of accepted payment methods (card, cash on delivery).
 * ROLE IN THE PROJECT: a PHP enum, so the code and the database can only contain these two values.
 * USED BY: Order, PaymentDto / CheckoutFormType (buyer chooses at checkout), admin order filters.
 */

namespace App\Enum;

/**
 * How an order is paid.
 *
 * SECURITY (rules 9, 15): a closed list. The value sent by the browser is converted with tryFrom()/EnumType, so any
 * other string ("free", "bank_transfer", "") is rejected before it reaches the business code.
 */
enum PaymentMethod: string
{
    case Card = 'card';
    case CashOnDelivery = 'cash_on_delivery';

    /** Text shown to the user for this payment method. */
    public function label(): string
    {
        return match ($this) {
            self::Card => 'Card',
            self::CashOnDelivery => 'Cash on delivery',
        };
    }

    /** Name of the Bootstrap icon shown next to the payment method. */
    public function icon(): string
    {
        return match ($this) {
            self::Card => 'credit-card',
            self::CashOnDelivery => 'cash-coin',
        };
    }
}
