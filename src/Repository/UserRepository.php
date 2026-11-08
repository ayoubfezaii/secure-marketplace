<?php

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
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, User::class);
    }

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
        if (!$user instanceof User) {
            throw new UnsupportedUserException(sprintf('Instances of "%s" are not supported.', $user::class));
        }

        $user->setPassword($newHashedPassword);
        $this->getEntityManager()->flush();
    }
}
