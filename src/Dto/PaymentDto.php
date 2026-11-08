<?php

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Simulated card payment (rules 10, 11, 15).
 *
 * SECURITY: this object is NEVER persisted. Only the holder name and the last 4 digits are copied to the Order.
 * The full number and the CVV exist only in memory during the request (PCI-DSS principle: don't store what you
 * don't need, never store the CVV at all).
 */
final class PaymentDto
{
    #[Assert\NotBlank]
    #[Assert\Length(min: 2, max: 100)]
    #[Assert\Regex(pattern: "/^[\\p{L}][\\p{L} .'-]*$/u", message: 'Please enter the name as written on the card.')]
    public ?string $cardHolder = null;

    // SECURITY: format + Luhn checksum validated server side (the HTML pattern is only a convenience).
    #[Assert\NotBlank]
    #[Assert\Regex(pattern: '/^\d{13,19}$/', message: 'The card number must contain 13 to 19 digits.')]
    #[Assert\Luhn(message: 'This card number is not valid.')]
    private ?string $cardNumber = null;

    #[Assert\NotBlank]
    #[Assert\Regex(pattern: '#^(0[1-9]|1[0-2])/\d{2}$#', message: 'Use the MM/YY format.')]
    public ?string $expiry = null;

    #[Assert\NotBlank]
    #[Assert\Regex(pattern: '/^\d{3,4}$/', message: 'The CVV has 3 or 4 digits.')]
    private ?string $cvv = null;

    public function getCardNumber(): ?string
    {
        return $this->cardNumber;
    }

    public function setCardNumber(#[\SensitiveParameter] ?string $cardNumber): void
    {
        // Spaces and dashes are accepted for readability, then removed.
        $this->cardNumber = null !== $cardNumber ? preg_replace('/[\s-]+/', '', $cardNumber) : null;
    }

    public function getCvv(): ?string
    {
        // Never re-displayed: the form field is a PasswordType and is always rendered empty.
        return null;
    }

    public function setCvv(#[\SensitiveParameter] ?string $cvv): void
    {
        $this->cvv = $cvv;
    }

    public function getLast4(): string
    {
        return substr((string) $this->cardNumber, -4);
    }

    #[Assert\Callback]
    public function validateExpiry(ExecutionContextInterface $context): void
    {
        if (null === $this->expiry || 1 !== preg_match('#^(0[1-9]|1[0-2])/(\d{2})$#', $this->expiry, $m)) {
            return;
        }

        $expiresAt = \DateTimeImmutable::createFromFormat('!Y-m', '20'.$m[2].'-'.$m[1])->modify('last day of this month 23:59:59');
        $now = new \DateTimeImmutable();

        if ($expiresAt < $now) {
            $context->buildViolation('This card has expired.')->atPath('expiry')->addViolation();
        } elseif ($expiresAt > $now->modify('+20 years')) {
            $context->buildViolation('This expiry date is not valid.')->atPath('expiry')->addViolation();
        }
    }

    /**
     * Wipes the sensitive values once the payment has been "processed".
     */
    public function eraseSensitiveData(): void
    {
        $this->cardNumber = null;
        $this->cvv = null;
    }

    /**
     * SECURITY: if this object is ever dumped or serialized (logs, profiler, session), the secrets don't appear.
     *
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return ['cardHolder' => $this->cardHolder, 'cardNumber' => '**** '.$this->getLast4(), 'expiry' => $this->expiry, 'cvv' => '***'];
    }

    /** @return array<string, mixed> */
    public function __serialize(): array
    {
        return $this->__debugInfo();
    }
}
