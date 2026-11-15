<?php

/**
 * FILE: src/Repository/ReviewRepository.php
 * WHAT: all the database queries about reviews (lists, averages, "did this buyer buy the product?").
 * ROLE IN THE PROJECT: feeds the product page and decides who is allowed to review.
 * USED BY: CatalogController (product page, everyone), ReviewVoter / ReviewController (buyer), HomeController,
 *   ProductRatingUpdater.
 */

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
    /** Tells Doctrine that this repository works on the Review entity. */
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Review::class);
    }

    /**
     * The reviews of a product, newest first, with their author and the seller reply (one query).
     *
     * @return list<Review>
     */
    public function findForProduct(Product $product): array
    {
        return $this->createQueryBuilder('r')
            ->innerJoin('r.author', 'a')->addSelect('a')
            ->leftJoin('r.reply', 'rep')->addSelect('rep')
            ->andWhere('r.product = :product')
            ->setParameter('product', $product)
            ->orderBy('r.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Average rating (1 decimal) and number of reviews of a product. avg is null when there is no review.
     *
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

    /**
     * Number of reviews per star value (5 => n, ..., 1 => n) for the distribution bars.
     *
     * @return array<int, int>
     */
    public function getDistribution(Product $product): array
    {
        $rows = $this->createQueryBuilder('r')
            ->select('r.rating AS rating, COUNT(r.id) AS cnt')
            ->andWhere('r.product = :product')->setParameter('product', $product)
            ->groupBy('r.rating')
            ->getQuery()->getArrayResult();

        // Start with 0 for every star value so the 5 bars always exist.
        $dist = [5 => 0, 4 => 0, 3 => 0, 2 => 0, 1 => 0];
        foreach ($rows as $row) {
            $dist[(int) $row['rating']] = (int) $row['cnt'];
        }

        return $dist;
    }

    /**
     * Latest 4-5 star reviews of active products (home page carousel).
     *
     * @return list<Review>
     */
    public function findLatestPositive(int $limit = 9): array
    {
        return $this->createQueryBuilder('r')
            ->innerJoin('r.author', 'a')->addSelect('a')
            ->innerJoin('r.product', 'p')->addSelect('p')
            ->andWhere('r.rating >= 4')->andWhere('p.isActive = true')
            ->orderBy('r.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()->getResult();
    }

    /** Share of 4-5 star reviews, in percent (home page "why shop with us"). */
    public function positiveRate(): int
    {
        $row = $this->createQueryBuilder('r')
            ->select('COUNT(r.id) AS total, SUM(CASE WHEN r.rating >= 4 THEN 1 ELSE 0 END) AS positive')
            ->getQuery()->getSingleResult();

        // 100 % when there is no review yet (avoids a division by zero).
        return (int) $row['total'] > 0 ? (int) round(100 * (int) $row['positive'] / (int) $row['total']) : 100;
    }

    /** True when $author already reviewed $product (a buyer can review a product only once). */
    public function hasReviewed(User $author, Product $product): bool
    {
        return null !== $this->findOneBy(['author' => $author, 'product' => $product]);
    }

    /**
     * SECURITY: access control - only real customers can review, which blocks fake reviews.
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
