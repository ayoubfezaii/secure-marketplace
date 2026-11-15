<?php

/**
 * FILE: src/Repository/WishlistItemRepository.php
 * WHAT: all the database queries about wishlist lines.
 * USED BY: WishlistController, Buyer\WishlistController, Buyer\DashboardController and the heart icons (buyer).
 */

namespace App\Repository;

use App\Entity\Product;
use App\Entity\User;
use App\Entity\WishlistItem;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * SECURITY (rule 4): every method is scoped to a user passed by the controller (the logged-in user).
 *
 * @extends ServiceEntityRepository<WishlistItem>
 */
class WishlistItemRepository extends ServiceEntityRepository
{
    /** Tells Doctrine that this repository works on the WishlistItem entity. */
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WishlistItem::class);
    }

    /**
     * The wishlist of $user with the products (one query), newest first.
     *
     * @return list<WishlistItem>
     */
    public function findForUser(User $user, ?int $limit = null): array
    {
        return $this->createQueryBuilder('w')
            ->innerJoin('w.product', 'p')->addSelect('p')
            ->andWhere('w.user = :user')->setParameter('user', $user)
            ->orderBy('w.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()->getResult();
    }

    /** The wishlist line of $user for $product, or null if the product is not saved. */
    public function findOneForUserAndProduct(User $user, Product $product): ?WishlistItem
    {
        return $this->findOneBy(['user' => $user, 'product' => $product]);
    }

    /** Number of products in the wishlist of $user. */
    public function countForUser(User $user): int
    {
        return (int) $this->createQueryBuilder('w')
            ->select('COUNT(w.id)')
            ->andWhere('w.user = :user')->setParameter('user', $user)
            ->getQuery()->getSingleScalarResult();
    }

    /**
     * @return array<int, true> product ids in the user's wishlist (to draw filled hearts)
     */
    public function productIdsForUser(User $user): array
    {
        // Read only the product ids (no need to load whole products).
        $rows = $this->createQueryBuilder('w')
            ->select('IDENTITY(w.product) AS pid')
            ->andWhere('w.user = :user')->setParameter('user', $user)
            ->getQuery()->getScalarResult();

        // Build [product id => true] so "is this product saved?" is a fast array lookup.
        return array_fill_keys(array_map('intval', array_column($rows, 'pid')), true);
    }
}
