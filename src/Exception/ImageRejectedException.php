<?php

/**
 * FILE: src/Exception/ImageRejectedException.php
 * WHAT: exception thrown when an image is refused (wrong type, too big, not a real image...).
 * ROLE IN THE PROJECT: part of the secure file upload: a refused file never reaches the disk.
 * USED BY: ImageStorage and SafeImageFetcher throw it; Seller\ProductController and Seller\ShopController catch it.
 */

namespace App\Exception;

/**
 * An image (uploaded or imported) failed validation. The message is safe to show to the user:
 * it never contains paths or internal details.
 */
class ImageRejectedException extends \RuntimeException
{
}
