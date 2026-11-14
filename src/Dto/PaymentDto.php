<?php

namespace App\Dto;

use App\Enum\PaymentMethod;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Payment step of the checkout: the method (card or cash on delivery) and, for a card, the simulated card data
 * (rules 9, 10, 11, 15).
 *
 * SECURITY: the card constraints are in the validation group "card". CheckoutFormType enables that group unless
 * the method is a valid CashOnDelivery, so the card is validated ONLY for card payments and a missing or forged
 * method can never skip the card checks (fail closed).
 *
 * SECURITY: this object is NEVER persisted. Only the holder name and the last 4 digits are copied to the Order.
 * The full number and the CVV exist only in memory during the request (PCI-DSS principle: don't store what you
 * don't need, never store the CVV at all).
 */
final class PaymentDto
{
    // SECURITY (rules 9, 15): whitelist. The form maps the submitted string to this enum (EnumType); anything else
    // than "card" / "cash_on_delivery" is an invalid choice and the form is rejected.
    #[Assert\NotNull(message: 'Please choose a payment method.')]
    public ?PaymentMethod $paymentMethod = PaymentMethod::Card;

    #[Assert\NotBlank(groups: ['card'])]
    #[Assert\Length(min: 2, max: 100, groups: ['card'])]
    #[Assert\Regex(pattern: "/^[\\p{L}][\\p{L} .'-]*$/u", message: 'Please enter the name as written on the card.', groups: ['card'])]
    public ?string $cardHolder = null;

    // SECURITY: format + Luhn checksum validated server side (the HTML pattern is only a convenience).
    #[Assert\NotBlank(groups: ['card'])]
    #[Assert\Regex(pattern: '/^\d{13,19}$/', message: 'The card number must contain 13 to 19 digits.', groups: ['card'])]
    #[Assert\Luhn(message: 'This card number is not valid.', groups: ['card'])]
    private ?string $cardNumber = null;

    #[Assert\NotBlank(groups: ['card'])]
    #[Assert\Regex(pattern: '#^(0[1-9]|1[0-2])/\d{2}$#', message: 'Use the MM/YY format.', groups: ['card'])]
    public ?string $expiry = null;

    #[Assert\NotBlank(groups: ['card'])]
    #[Assert\Regex(pattern: '/^\d{3,4}$/', message: 'The CVV has 3 or 4 digits.', groups: ['card'])]
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
        // Used by the validator/form only. Never re-displayed: the form field is a PasswordType (always_empty).
        return $this->cvv;
    }

    public function setCvv(#[\SensitiveParameter] ?string $cvv): void
    {
        $this->cvv = $cvv;
    }

    public function getLast4(): string
    {
        return substr((string) $this->cardNumber, -4);
    }

    public function isCashOnDelivery(): bool
    {
        return PaymentMethod::CashOnDelivery === $this->paymentMethod;
    }

    #[Assert\Callback(groups: ['card'])]
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
     * Cash on delivery: whatever was typed in the (hidden) card fields is thrown away, unvalidated and unused.
     */
    public function eraseCardData(): void
    {
        $this->eraseSensitiveData();
        $this->cardHolder = null;
        $this->expiry = null;
    }

    /**
     * SECURITY: if this object is ever dumped or serialized (logs, profiler, session), the secrets don't appear.
     *
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return ['paymentMethod' => $this->paymentMethod?->value, 'cardHolder' => $this->cardHolder, 'cardNumber' => '**** '.$this->getLast4(), 'expiry' => $this->expiry, 'cvv' => '***'];
    }

    /** @return array<string, mixed> */
    public function __serialize(): array
    {
        return $this->__debugInfo();
    }
}
