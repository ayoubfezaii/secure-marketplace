<?php

/**
 * FILE: src/Repository/AddressRepository.php
 * WHAT: all the database queries about saved addresses.
 * ROLE IN THE PROJECT: a repository is the only place where an entity is read from the database.
 * USED BY: Buyer\AddressController and CheckoutController (buyer).
 */

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
    /** Tells Doctrine that this repository works on the Address entity. */
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Address::class);
    }

    /**
     * All the addresses of one buyer, default address first, then oldest first.
     *
     * @param User $user the logged-in buyer
     *
     * @return list<Address>
     */
    public function findForUser(User $user): array
    {
        return $this->createQueryBuilder('a')
            ->andWhere('a.user = :user')->setParameter('user', $user)
            ->orderBy('a.isDefault', 'DESC')->addOrderBy('a.createdAt', 'ASC')
            ->getQuery()->getResult();
    }

    /** Number of addresses saved by $user (used to enforce the limit of 5). */
    public function countForUser(User $user): int
    {
        return (int) $this->createQueryBuilder('a')->select('COUNT(a.id)')
            ->andWhere('a.user = :user')->setParameter('user', $user)
            ->getQuery()->getSingleScalarResult();
    }

    /**
     * Finds ONE address by its UUID, but only if it belongs to $user.
     * SECURITY: IDOR - the owner is part of the WHERE clause, so the UUID of another buyer gives null.
     *
     * @param User   $user the logged-in buyer
     * @param string $uuid UUID taken from the URL
     *
     * @return Address|null null when not found or not owned by $user
     */
    public function findOneForUser(User $user, string $uuid): ?Address
    {
        // A text that is not a valid UUID cannot match anything: stop before querying.
        if (!Uuid::isValid($uuid)) {
            return null;
        }

        // SECURITY: SQL injection - :user and :uuid are bound parameters, never glued into the query text.
        return $this->createQueryBuilder('a')
            ->andWhere('a.user = :user')->andWhere('a.uuid = :uuid')
            ->setParameter('user', $user)->setParameter('uuid', Uuid::fromString($uuid), 'uuid')
            ->getQuery()->getOneOrNullResult();
    }

    /** The address to preselect at checkout (the default one, or the oldest), or null if none. */
    public function findDefaultForUser(User $user): ?Address
    {
        return $this->findForUser($user)[0] ?? null;
    }

    /** Makes $address the only default address of its owner. */
    public function makeDefault(Address $address): void
    {
        // Set the flag to true on $address and to false on all the other addresses of the same buyer.
        foreach ($this->findForUser($address->getUser()) as $other) {
            $other->setIsDefault($other === $address);
        }
    }
}
