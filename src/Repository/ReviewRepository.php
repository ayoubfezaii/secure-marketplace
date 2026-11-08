<?php

namespace App\Repository;

use App\Entity\Order;
use App\Entity\OrderItem;
use App\Entity\Product;
use App\Entity\Review;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Review>
 */
class ReviewRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Review::class);
    }

    /**
     * @return list<Review>
     */
    public function findForProduct(Product $product): array
    {
        return $this->createQueryBuilder('r')
            ->innerJoin('r.author', 'a')->addSelect('a')
            ->andWhere('r.product = :product')
            ->setParameter('product', $product)
            ->orderBy('r.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return array{avg: float|null, count: int}
     */
    public function getRatingSummary(Product $product): array
    {
        $row = $this->createQueryBuilder('r')
            ->select('AVG(r.rating) AS avg, COUNT(r.id) AS cnt')
            ->andWhere('r.product = :product')
            ->setParameter('product', $product)
            ->getQuery()
            ->getSingleResult();

        return ['avg' => null !== $row['avg'] ? round((float) $row['avg'], 1) : null, 'count' => (int) $row['cnt']];
    }

    public function hasReviewed(User $author, Product $product): bool
    {
        return null !== $this->findOneBy(['author' => $author, 'product' => $product]);
    }

    /**
     * True if $buyer has a non-cancelled order containing $product (paid, shipped or delivered).
     */
    public function hasPurchased(User $buyer, Product $product): bool
    {
        return (int) $this->getEntityManager()->createQueryBuilder()
            ->select('COUNT(i.id)')
            ->from(OrderItem::class, 'i')
            ->innerJoin('i.order', 'o')
            ->andWhere('o.buyer = :buyer')
            ->andWhere('i.product = :product')
            ->andWhere('i.status IN (:statuses)')
            ->setParameter('buyer', $buyer)
            ->setParameter('product', $product)
            ->setParameter('statuses', [Order::STATUS_PAID, Order::STATUS_SHIPPED, Order::STATUS_DELIVERED])
            ->getQuery()
            ->getSingleScalarResult() > 0;
    }

    /**
     * Products the buyer purchased but has not reviewed yet (buyer dashboard).
     *
     * @return list<Product>
     */
    public function findProductsToReview(User $buyer, int $limit = 10): array
    {
        return $this->getEntityManager()->createQueryBuilder()
            ->select('DISTINCT p')
            ->from(Product::class, 'p')
            ->innerJoin(OrderItem::class, 'i', 'WITH', 'i.product = p')
            ->innerJoin('i.order', 'o')
            ->andWhere('o.buyer = :buyer')
            ->andWhere('i.status IN (:statuses)')
            ->andWhere('p.id NOT IN (SELECT IDENTITY(r.product) FROM App\Entity\Review r WHERE r.author = :buyer)')
            ->setParameter('buyer', $buyer)
            ->setParameter('statuses', [Order::STATUS_PAID, Order::STATUS_SHIPPED, Order::STATUS_DELIVERED])
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
