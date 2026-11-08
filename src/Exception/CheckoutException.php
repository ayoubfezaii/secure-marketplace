<?php

namespace App\Exception;

/**
 * Checkout refused (stock, availability...). The message is safe to show to the buyer.
 */
class CheckoutException extends \RuntimeException
{
}
