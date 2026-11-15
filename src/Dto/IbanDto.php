<?php

/**
 * FILE: src/Dto/IbanDto.php
 * WHAT: DTO (Data Transfer Object) = a small object that only carries the data of the IBAN form.
 * ROLE IN THE PROJECT: the form fills this object instead of the User entity (defence against mass assignment).
 * USED BY: IbanFormType and Seller\ShopController::payouts (seller).
 */

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Payout account form (sellers only). Write-only: never pre-filled with the stored value (which is encrypted anyway).
 */
final class IbanDto
{
    // SECURITY: validation - Assert\Iban checks the country format and the checksum of the IBAN.
    #[Assert\Iban(message: 'This is not a valid IBAN.')]
    #[Assert\Length(max: 42)]
    public ?string $iban = null;

    // True when the seller ticked "Remove my IBAN".
    public bool $removeIban = false;

    /**
     * The IBAN without spaces and in upper case.
     *
     * @return string|null null when the field was left empty
     */
    public function normalizedIban(): ?string
    {
        if (null === $this->iban || '' === trim($this->iban)) {
            return null;
        }

        return strtoupper(preg_replace('/\s+/', '', $this->iban) ?? '');
    }
}
