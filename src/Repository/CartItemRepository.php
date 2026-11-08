<?php

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
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CartItem::class);
    }

    /**
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

    public function findOneForUserAndProduct(User $user, Product $product): ?CartItem
    {
        return $this->findOneBy(['user' => $user, 'product' => $product]);
    }

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
