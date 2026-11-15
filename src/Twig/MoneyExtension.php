<?php

/**
 * FILE: src/Twig/MoneyExtension.php
 * WHAT: Twig extension that adds the |price filter.
 * ROLE IN THE PROJECT: every price in every template is printed with this filter.
 * USED BY: all templates that show an amount (everyone).
 */

namespace App\Twig;

use App\Service\Money;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

/**
 * {{ amount|price }}: the single way to print money in a template (storefront, cart, checkout, orders, dashboards).
 * 1250.5 -> "1 250,500 DT". Changing the currency or its format means changing App\Service\Money only.
 *
 * SECURITY (rule 2): the filter returns plain text (digits, spaces, a comma and "DT") and is NOT marked as safe,
 * so Twig still auto-escapes its output like any other value.
 */
final class MoneyExtension extends AbstractExtension
{
    /** Declares the filter "price", which calls Money::format(). */
    public function getFilters(): array
    {
        return [
            new TwigFilter('price', Money::format(...)),
        ];
    }
}
