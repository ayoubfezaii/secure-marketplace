<?php

/**
 * FILE: src/Security/PasswordPolicy.php
 * WHAT: the rules a password must respect (length, letter, digit, not in a known leak).
 * ROLE IN THE PROJECT: one single list of rules shared by all the password forms.
 * USED BY: RegistrationFormType (visitor), NewPasswordFormType (password reset), ChangePasswordFormType (logged-in user).
 */

namespace App\Security;

use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Single source of truth for the password policy (rule 15).
 * Used by registration, password reset and change password, so the rules can't drift apart.
 */
final class PasswordPolicy
{
    // Minimum number of characters. Long passwords are the best defence against guessing.
    public const MIN_LENGTH = 12;

    /**
     * Returns the validation rules to put on a password field.
     *
     * @return list<Constraint>
     */
    public static function constraints(): array
    {
        return [
            // The field cannot be empty.
            new Assert\NotBlank(message: 'Please enter a password.'),
            // SECURITY: max 4096 because Symfony's hashers reject longer input (prevents DoS through huge passwords).
            new Assert\Length(min: self::MIN_LENGTH, max: 4096, minMessage: 'Your password must be at least {{ limit }} characters long.'),
            // At least one letter and one digit.
            new Assert\Regex(pattern: '/\p{L}/u', message: 'Your password must contain at least one letter.'),
            new Assert\Regex(pattern: '/\d/', message: 'Your password must contain at least one number.'),
            // SECURITY: rejects passwords that appear in known data breaches (k-anonymity API: only the
            // first 5 chars of the SHA-1 are sent). skipOnError keeps registration working offline.
            new Assert\NotCompromisedPassword(skipOnError: true),
        ];
    }
}
