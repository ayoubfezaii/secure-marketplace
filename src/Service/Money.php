<?php

/**
 * FILE: src/Service/Money.php
 * WHAT: constants and two functions to format money amounts (Tunisian dinar, 3 decimals).
 * ROLE IN THE PROJECT: one place for the currency, so every page shows prices the same way.
 * USED BY: entities (Product, Order...), services, App\Twig\MoneyExtension (the |price filter in templates),
 *   the CSV export. Tested by tests/Unit/MoneyTest.
 */

namespace App\Service;

/**
 * The ONE place that knows the platform currency: Tunisian dinar (TND, written "DT"), 3 decimals (millimes).
 *
 * Every amount is a DECIMAL(…, 3) string in the database and is computed with bcmath at SCALE (no float rounding
 * on money). Display goes through format() (Twig filter |price), exports through decimal().
 *
 * SECURITY (rule 10): this class only FORMATS amounts that the server read from the database. It never parses an
 * amount coming from a request; the only price a user can type (a seller's product price) is validated by
 * Product::$price (PATTERN + MAX_PRICE).
 */
final class Money
{
    // ISO code and symbol of the currency.
    public const CURRENCY = 'TND';
    public const SYMBOL = 'DT';
    /** Decimals of the dinar (1 DT = 1000 millimes). Also the bcmath scale used everywhere. */
    public const SCALE = 3;
    // Zero written with 3 decimals; start value of every total.
    public const ZERO = '0.000';
    /** Highest product price a seller may set, in DT. */
    public const MAX_PRICE = 300000;
    /** A price as typed/stored: up to 6 integer digits, at most 3 decimals, dot as separator. */
    public const PATTERN = '/^\d{1,6}(\.\d{1,3})?$/';

    /**
     * Tunisian display format: 3 decimals, space as thousands separator, comma as decimal separator, then " DT".
     * 1250.5 -> "1 250,500 DT".
     */
    public static function format(string|int|float|null $amount): string
    {
        return number_format(self::toFloat($amount), self::SCALE, ',', ' ').' '.self::SYMBOL;
    }

    /**
     * Machine format for exports and JSON: 3 decimals, dot, no separator, no symbol. 1250.5 -> "1250.500".
     */
    public static function decimal(string|int|float|null $amount): string
    {
        return number_format(self::toFloat($amount), self::SCALE, '.', '');
    }

    /** Converts any input to a float for display. */
    private static function toFloat(string|int|float|null $amount): float
    {
        // Anything that is not a number (null, '', a stray string) is shown as zero rather than raising an error.
        return is_numeric($amount) ? (float) $amount : 0.0;
    }
}
