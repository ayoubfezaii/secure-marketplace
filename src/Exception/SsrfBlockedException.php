<?php

/**
 * FILE: src/Exception/SsrfBlockedException.php
 * WHAT: exception thrown when "import image from URL" is refused by the SSRF checks.
 * ROLE IN THE PROJECT: keeps two texts apart: a generic message for the user and the real reason for the audit log.
 * USED BY: SafeImageFetcher throws it; Seller\ProductController catches it and logs the attempt.
 */

namespace App\Exception;

/**
 * A URL import was refused by the SSRF protection (rule 8). The message is generic on purpose: it doesn't
 * tell an attacker which internal address was reached or which check failed.
 */
class SsrfBlockedException extends ImageRejectedException
{
    /**
     * @param string $reason  internal reason (e.g. "private_ip:127.0.0.1"), never shown to the user
     * @param string $message generic text shown to the user
     */
    public function __construct(private readonly string $reason, string $message = 'This URL is not allowed.')
    {
        parent::__construct($message);
    }

    /** Internal reason, for the audit log only. */
    public function getReason(): string
    {
        return $this->reason;
    }
}
