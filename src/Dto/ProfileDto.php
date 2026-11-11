<?php

namespace App\Dto;

use App\Entity\User;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * SECURITY (rule 9, mass assignment): the profile form is bound to THIS object, not to the User entity.
 * It only has the fields a user may change. email, roles, password, isVerified, failedAttempts, lockedUntil, the 2FA
 * fields and the IBAN (separate, seller-only Payouts page) simply don't exist here, so no request can set them.
 * The controller copies the allowed values to the entity explicitly.
 */
final class ProfileDto
{
    #[Assert\NotBlank(message: 'Please enter your name.')]
    #[Assert\Length(min: 2, max: 60)]
    #[Assert\Regex(pattern: '/^[\p{L}\p{N} .\'_-]+$/u', message: 'Use letters, numbers, spaces, dots, apostrophes, dashes or underscores only.')]
    public ?string $displayName = null;

    #[Assert\Regex(pattern: '/^\+?[0-9 ]{6,20}$/', message: 'Please enter a valid phone number (digits, spaces, optional +).')]
    public ?string $phone = null;

    public static function fromUser(User $user): self
    {
        $dto = new self();
        $dto->displayName = $user->getDisplayName();
        $dto->phone = $user->getPhone();

        return $dto;
    }
}
