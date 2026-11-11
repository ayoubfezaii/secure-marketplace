<?php

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
    public const EDIT = 'ADDRESS_EDIT';
    public const DELETE = 'ADDRESS_DELETE';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return in_array($attribute, [self::EDIT, self::DELETE], true) && $subject instanceof Address;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();

        /** @var Address $subject */
        return $user instanceof User && $user->isBuyer() && $subject->getUser()->getId() === $user->getId();
    }
}
