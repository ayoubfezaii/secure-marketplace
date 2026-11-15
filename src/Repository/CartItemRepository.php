<?php

/**
 * FILE: src/Repository/CartItemRepository.php
 * WHAT: all the database queries about cart lines.
 * ROLE IN THE PROJECT: reads the cart of the logged-in buyer.
 * USED BY: CartController, CheckoutController, CheckoutService and the cart counter in the header (buyer).
 */

namespace App\Repository;

use App\Entity\CartItem;
use App\Entity\Product;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * SECURITY (rule 4): every method is scoped to a user. A cart line is always looked up by (current user, product),
 * so there is no cart-item id in URLs that could point to someone else's cart.
 *
 * @extends ServiceEntityRepository<CartItem>
 */
class CartItemRepository extends ServiceEntityRepository
{
    /** Tells Doctrine that this repository works on the CartItem entity. */
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CartItem::class);
    }

    /**
     * The cart lines of $user with their products (loaded in the same query), oldest first.
     *
     * @return list<CartItem>
     */
    public function findForUser(User $user): array
    {
        return $this->createQueryBuilder('c')
            ->innerJoin('c.product', 'p')->addSelect('p')
            ->andWhere('c.user = :user')
            ->setParameter('user', $user)
            ->orderBy('c.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** The cart line of $user for $product, or null if the product is not in the cart. */
    public function findOneForUserAndProduct(User $user, Product $product): ?CartItem
    {
        return $this->findOneBy(['user' => $user, 'product' => $product]);
    }

    /** Total number of units in the cart of $user (the number shown on the cart icon). 0 when empty. */
    public function countUnitsForUser(User $user): int
    {
        return (int) $this->createQueryBuilder('c')
            ->select('COALESCE(SUM(c.quantity), 0)')
            ->andWhere('c.user = :user')
            ->setParameter('user', $user)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
