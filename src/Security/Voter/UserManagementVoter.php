<?php

/**
 * FILE: src/Security/Voter/UserManagementVoter.php
 * WHAT: the voter for admin actions on user accounts (view, lock, change role, reset password, reset 2FA).
 * ROLE IN THE PROJECT: limits what an admin can do to other accounts (privilege escalation defence).
 * USED BY: Admin\UserController (admin) and the admin user templates.
 */

namespace App\Security\Voter;

use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Admin actions on user accounts (rule 4).
 *
 * USER_VIEW:   admin.
 * USER_MANAGE: admin, AND the target is not the admin himself, AND the target is not an administrator.
 *              Covers lock/unlock, buyer<->seller switch, forced password reset and 2FA reset.
 *
 * SECURITY: an admin can never lock, demote or reset himself (no self-lockout, no accidental loss of the last
 * admin), and a single stolen admin session can't take over or lock out the other administrators. Admin accounts
 * are managed with console commands only; nobody can be promoted to admin from the web.
 *
 * @extends Voter<string, User>
 */
final class UserManagementVoter extends Voter
{
    // Names of the permissions this voter understands (explained in the class comment above).
    public const VIEW = 'USER_VIEW';
    public const MANAGE = 'USER_MANAGE';

    /** Says if this voter handles the question: USER_VIEW or USER_MANAGE on a User object. */
    protected function supports(string $attribute, mixed $subject): bool
    {
        return in_array($attribute, [self::VIEW, self::MANAGE], true) && $subject instanceof User;
    }

    /**
     * The decision itself.
     *
     * @param mixed          $subject the account the admin wants to act on
     * @param TokenInterface $token   gives the logged-in user ("me")
     *
     * @return bool true = allowed, false = denied
     */
    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $me = $token->getUser();
        // SECURITY: access control - both permissions need an admin.
        if (!$me instanceof User || !$me->isAdmin()) {
            return false;
        }
        // Viewing has no other condition.
        if (self::VIEW === $attribute) {
            return true;
        }

        // SECURITY: privilege escalation - managing is refused on myself and on any other admin.
        /** @var User $subject */
        return $subject->getId() !== $me->getId() && !$subject->isAdmin();
    }
}
