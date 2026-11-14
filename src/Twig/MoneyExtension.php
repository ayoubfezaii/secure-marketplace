<?php

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
    public function getFilters(): array
    {
        return [
            new TwigFilter('price', Money::format(...)),
        ];
    }
}
