<?php

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
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WishlistItem::class);
    }

    /**
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

    public function findOneForUserAndProduct(User $user, Product $product): ?WishlistItem
    {
        return $this->findOneBy(['user' => $user, 'product' => $product]);
    }

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
        $rows = $this->createQueryBuilder('w')
            ->select('IDENTITY(w.product) AS pid')
            ->andWhere('w.user = :user')->setParameter('user', $user)
            ->getQuery()->getScalarResult();

        return array_fill_keys(array_map('intval', array_column($rows, 'pid')), true);
    }
}
