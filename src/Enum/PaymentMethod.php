<?php

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

    public function label(): string
    {
        return match ($this) {
            self::Card => 'Card',
            self::CashOnDelivery => 'Cash on delivery',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Card => 'credit-card',
            self::CashOnDelivery => 'cash-coin',
        };
    }
}
