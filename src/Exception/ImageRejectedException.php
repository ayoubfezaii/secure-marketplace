<?php

namespace App\Exception;

/**
 * An image (uploaded or imported) failed validation. The message is safe to show to the user:
 * it never contains paths or internal details.
 */
class ImageRejectedException extends \RuntimeException
{
}
