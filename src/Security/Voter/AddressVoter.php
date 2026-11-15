<?php

/**
 * FILE: src/Security/Voter/AddressVoter.php
 * WHAT: the voter that answers "may this user edit or delete THIS address?".
 * ROLE IN THE PROJECT: a voter is the Symfony tool for access control on one object (defence against IDOR).
 * USED BY: Buyer\AddressController through denyAccessUnlessGranted() / #[IsGranted] (buyer).
 */

namespace App\Security\Voter;

use App\Entity\Address;
use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * ADDRESS_EDIT / ADDRESS_DELETE: only the buyer who owns the address (rule 4, IDOR on /buyer/addresses/{uuid}).
 *
 * @extends Voter<string, Address>
 */
final class AddressVoter extends Voter
{
    // Names of the permissions this voter understands.
    public const EDIT = 'ADDRESS_EDIT';
    public const DELETE = 'ADDRESS_DELETE';

    /**
     * Symfony asks every voter: "do you handle this permission for this object?".
     *
     * @param string $attribute the permission name (ADDRESS_EDIT...)
     * @param mixed  $subject   the object to protect
     *
     * @return bool true when this voter must decide
     */
    protected function supports(string $attribute, mixed $subject): bool
    {
        return in_array($attribute, [self::EDIT, self::DELETE], true) && $subject instanceof Address;
    }

    /**
     * The decision itself.
     *
     * @param TokenInterface $token gives the logged-in user
     *
     * @return bool true = access allowed, false = access denied (error 403)
     */
    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();

        // SECURITY: IDOR - allowed only when the address belongs to the logged-in buyer. The owner is compared with
        // the user of the session, so changing the UUID in the URL to someone else's address gives "access denied".
        /** @var Address $subject */
        return $user instanceof User && $user->isBuyer() && $subject->getUser()->getId() === $user->getId();
    }
}
