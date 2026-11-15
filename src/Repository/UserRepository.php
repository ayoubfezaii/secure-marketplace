<?php

/**
 * FILE: src/Repository/UserRepository.php
 * WHAT: all the database queries about user accounts.
 * ROLE IN THE PROJECT: Symfony Security uses it to load the user at login; the admin panel uses it to list users.
 * USED BY: the login system (everyone), ResetPasswordController, Admin\UserController and AdminStats (admin).
 */

namespace App\Repository;

use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\PasswordUpgraderInterface;

/**
 * @extends ServiceEntityRepository<User>
 */
class UserRepository extends ServiceEntityRepository implements PasswordUpgraderInterface
{
    /** Tells Doctrine that this repository works on the User entity. */
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, User::class);
    }

    /**
     * Finds an account by email (case-insensitive: the email is lower-cased like in User::setEmail()).
     *
     * @return User|null null when no account has this email
     */
    public function findOneByEmail(string $email): ?User
    {
        // SECURITY: parameter binding, never string concatenation (rule 1, SQL injection).
        return $this->createQueryBuilder('u')
            ->andWhere('u.email = :email')
            ->setParameter('email', mb_strtolower(trim($email)))
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Admin user list. Search text is a bound parameter with LIKE wildcards escaped (rule 1).
     *
     * @param string|null $search part of an email or display name
     * @param string|null $role   ROLE_BUYER, ROLE_SELLER or ROLE_ADMIN
     *
     * @return QueryBuilder the query, not executed yet (the caller adds the pagination)
     */
    public function createAdminQueryBuilder(?string $search, ?string $role): QueryBuilder
    {
        $qb = $this->createQueryBuilder('u')->orderBy('u.createdAt', 'DESC');

        if (null !== $search && '' !== $search) {
            $qb->andWhere('(u.email LIKE :q OR u.displayName LIKE :q)')
                ->setParameter('q', '%'.addcslashes($search, '%_\\').'%');
        }
        if (null !== $role && in_array($role, [User::ROLE_BUYER, User::ROLE_SELLER, User::ROLE_ADMIN], true)) {
            // roles is a JSON column: match the quoted role name. The role comes from a whitelist.
            $qb->andWhere('u.roles LIKE :role')->setParameter('role', '%"'.$role.'"%');
        }

        return $qb;
    }

    /**
     * Number of accounts that have $role (admin dashboard).
     * The role name comes from the code (a constant), and is still sent as a bound parameter.
     */
    public function countByRole(string $role): int
    {
        return (int) $this->createQueryBuilder('u')
            ->select('COUNT(u.id)')
            ->andWhere('u.roles LIKE :role')
            ->setParameter('role', '%"'.$role.'"%')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Accounts that cannot log in right now: temporary lockout still running, or blocked by an admin.
     *
     * @return list<User>
     */
    public function findLocked(): array
    {
        return $this->createQueryBuilder('u')
            ->andWhere('(u.lockedUntil > :now OR u.blocked = true)')
            ->setParameter('now', new \DateTimeImmutable())
            ->orderBy('u.lockedUntil', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Re-hashes the password on login when the "auto" algorithm's cost settings change.
     */
    public function upgradePassword(PasswordAuthenticatedUserInterface $user, string $newHashedPassword): void
    {
        // This repository only knows App\Entity\User.
        if (!$user instanceof User) {
            throw new UnsupportedUserException(sprintf('Instances of "%s" are not supported.', $user::class));
        }

        // Save the new hash.
        $user->setPassword($newHashedPassword);
        $this->getEntityManager()->flush();
    }
}
