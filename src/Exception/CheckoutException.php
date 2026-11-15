<?php

/**
 * FILE: src/Exception/CheckoutException.php
 * WHAT: exception thrown when an order cannot be placed (empty cart, no stock, product removed...).
 * ROLE IN THE PROJECT: lets CheckoutService stop the transaction and give a clear message.
 * USED BY: CheckoutService throws it; CheckoutController catches it and shows the message (buyer flow).
 */

namespace App\Exception;

/**
 * Checkout refused (stock, availability...). The message is safe to show to the buyer.
 */
class CheckoutException extends \RuntimeException
{
}
