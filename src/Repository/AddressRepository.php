<?php

namespace App\Repository;

use App\Entity\Address;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * SECURITY (rule 4): lookups are always scoped to the owner, so a UUID belonging to another buyer returns nothing.
 *
 * @extends ServiceEntityRepository<Address>
 */
class AddressRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Address::class);
    }

    /**
     * @return list<Address>
     */
    public function findForUser(User $user): array
    {
        return $this->createQueryBuilder('a')
            ->andWhere('a.user = :user')->setParameter('user', $user)
            ->orderBy('a.isDefault', 'DESC')->addOrderBy('a.createdAt', 'ASC')
            ->getQuery()->getResult();
    }

    public function countForUser(User $user): int
    {
        return (int) $this->createQueryBuilder('a')->select('COUNT(a.id)')
            ->andWhere('a.user = :user')->setParameter('user', $user)
            ->getQuery()->getSingleScalarResult();
    }

    public function findOneForUser(User $user, string $uuid): ?Address
    {
        if (!Uuid::isValid($uuid)) {
            return null;
        }

        return $this->createQueryBuilder('a')
            ->andWhere('a.user = :user')->andWhere('a.uuid = :uuid')
            ->setParameter('user', $user)->setParameter('uuid', Uuid::fromString($uuid), 'uuid')
            ->getQuery()->getOneOrNullResult();
    }

    public function findDefaultForUser(User $user): ?Address
    {
        return $this->findForUser($user)[0] ?? null;
    }

    /** Makes $address the only default address of its owner. */
    public function makeDefault(Address $address): void
    {
        foreach ($this->findForUser($address->getUser()) as $other) {
            $other->setIsDefault($other === $address);
        }
    }
}
