<?php

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
    public const VIEW = 'USER_VIEW';
    public const MANAGE = 'USER_MANAGE';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return in_array($attribute, [self::VIEW, self::MANAGE], true) && $subject instanceof User;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $me = $token->getUser();
        if (!$me instanceof User || !$me->isAdmin()) {
            return false;
        }
        if (self::VIEW === $attribute) {
            return true;
        }

        /** @var User $subject */
        return $subject->getId() !== $me->getId() && !$subject->isAdmin();
    }
}
