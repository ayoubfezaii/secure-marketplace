<?php

/**
 * FILE: src/Security/UserChecker.php
 * WHAT: extra checks on the account done by Symfony during login.
 * ROLE IN THE PROJECT: refuses blocked accounts and accounts locked after too many wrong passwords.
 * USED BY: Symfony Security (declared as user_checker in security.yaml) at every login attempt, for all roles.
 */

namespace App\Security;

use App\Entity\User;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAccountStatusException;
use Symfony\Component\Security\Core\User\UserCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Runs before the password is checked (checkPreAuth) on every login attempt.
 */
final class UserChecker implements UserCheckerInterface
{
    // Messages shown on the login page.
    public const LOCKED_MESSAGE = 'Too many failed login attempts. Please try again later.';
    public const BLOCKED_MESSAGE = 'This account has been blocked. Please contact support.';

    /**
     * Called by Symfony after the account is found and BEFORE the password is compared.
     * Throwing an exception here stops the login and shows the message.
     *
     * @param UserInterface $user the account that tries to log in
     */
    public function checkPreAuth(UserInterface $user): void
    {
        // Only our own User class is checked.
        if (!$user instanceof User) {
            return;
        }

        // SECURITY (rule 4): exclusive roles. A tampered row (e.g. roles edited in the DB to buyer+admin) is refused.
        $known = array_intersect($user->getRoles(), [User::ROLE_BUYER, User::ROLE_SELLER, User::ROLE_ADMIN]);
        if (1 !== count($known)) {
            throw new CustomUserMessageAccountStatusException('This account is misconfigured. Please contact support.');
        }

        // SECURITY: an account blocked by an administrator is refused, whatever the password.
        if ($user->isBlocked()) {
            throw new CustomUserMessageAccountStatusException(self::BLOCKED_MESSAGE);
        }

        // SECURITY: a locked account is rejected BEFORE the password is verified, so an attacker
        // can't keep guessing while it is locked, even with the right password (rule 6).
        if ($user->isLocked()) {
            throw new CustomUserMessageAccountStatusException(self::LOCKED_MESSAGE);
        }
    }

    /** Called after the password is verified. Nothing more to check here. */
    public function checkPostAuth(UserInterface $user): void
    {
    }
}
