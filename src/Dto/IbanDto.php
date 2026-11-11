<?php

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Payout account form (sellers only). Write-only: never pre-filled with the stored value (which is encrypted anyway).
 */
final class IbanDto
{
    #[Assert\Iban(message: 'This is not a valid IBAN.')]
    #[Assert\Length(max: 42)]
    public ?string $iban = null;

    public bool $removeIban = false;

    public function normalizedIban(): ?string
    {
        if (null === $this->iban || '' === trim($this->iban)) {
            return null;
        }

        return strtoupper(preg_replace('/\s+/', '', $this->iban) ?? '');
    }
}
