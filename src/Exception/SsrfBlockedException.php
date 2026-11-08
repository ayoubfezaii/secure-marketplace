<?php

namespace App\Exception;

/**
 * A URL import was refused by the SSRF protection (rule 8). The message is generic on purpose: it doesn't
 * tell an attacker which internal address was reached or which check failed.
 */
class SsrfBlockedException extends ImageRejectedException
{
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
