<?php

/**
 * FILE: src/Repository/ProductRepository.php
 * WHAT: all the database queries about products (search, best sellers, similar products, seller and admin lists).
 * ROLE IN THE PROJECT: the catalogue search lives here. It is the best file to show the SQL injection defence.
 * USED BY: CatalogController, HomeController, ShopController (everyone), Seller\ProductController and
 *   Seller\DashboardController (seller), Admin\ModerationController (admin).
 */

namespace App\Repository;

use App\Dto\ProductSearchCriteria;
use App\Entity\Category;
use App\Entity\Order;
use App\Entity\OrderItem;
use App\Entity\Product;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Doctrine\Persistence\ManagerRegistry;

/**
 * SECURITY (rule 1): every query uses QueryBuilder/DQL with bound parameters. No string concatenation of input.
 *
 * @extends ServiceEntityRepository<Product>
 */
class ProductRepository extends ServiceEntityRepository
{
    /** Order statuses that count as a sale (best sellers, sales counts). */
    public const SOLD = [Order::STATUS_PAID, Order::STATUS_SHIPPED, Order::STATUS_DELIVERED];

    /** Tells Doctrine that this repository works on the Product entity. */
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Product::class);
    }

    /**
     * All the products of one seller (active or hidden), newest first, with category and images.
     * SECURITY: IDOR - filtered on the seller, so a seller only lists their own products.
     *
     * @return list<Product>
     */
    public function findBySeller(User $seller): array
    {
        return $this->preloadImages($this->createQueryBuilder('p')
            ->leftJoin('p.category', 'c')->addSelect('c')
            ->andWhere('p.seller = :seller')
            ->setParameter('seller', $seller)
            ->orderBy('p.createdAt', 'DESC')
            ->getQuery()
            ->getResult());
    }

    /**
     * Finds a product by the UUID found in the URL, or null.
     * SECURITY: SQL injection - findOneBy() sends the value as a bound parameter.
     */
    public function findOneByUuid(string $uuid): ?Product
    {
        return $this->findOneBy(['uuid' => $uuid]);
    }

    /**
     * Public catalogue search. Only active products.
     *
     * @param ProductSearchCriteria $c        the filters of the search form, already validated
     * @param Category|null         $category limit to one category
     * @param User|null             $seller   limit to one seller (shop page)
     *
     *
     * @return Paginator<Product>
     */
    public function search(ProductSearchCriteria $c, ?Category $category = null, ?User $seller = null): Paginator
    {
        // Base query: product + seller + category + images in one query; hidden products are excluded.
        $qb = $this->createQueryBuilder('p')
            ->innerJoin('p.seller', 's')->addSelect('s')
            ->innerJoin('p.category', 'c')->addSelect('c')
            ->leftJoin('p.images', 'img')->addSelect('img')
            ->andWhere('p.isActive = true');

        // Each filter below is added only when the visitor used it.
        if ('' !== $c->query) {
            // SECURITY (rule 1): the text is a bound parameter. LIKE wildcards (% and _) typed by the user are
            // escaped too, so "%" can't be used to dump everything or build slow patterns.
            $qb->andWhere('(p.name LIKE :q OR p.description LIKE :q)')
                ->setParameter('q', '%'.addcslashes($c->query, '%_\\').'%');
        }
        if (null !== $category) {
            $qb->andWhere('p.category = :category')->setParameter('category', $category);
        }
        if (null !== $seller) {
            $qb->andWhere('p.seller = :seller')->setParameter('seller', $seller);
        }
        // Price, rating and stock filters: always ":name" placeholders, the value is sent separately.
        if (null !== $c->minPrice) {
            $qb->andWhere('p.price >= :min')->setParameter('min', $c->minPrice);
        }
        if (null !== $c->maxPrice) {
            $qb->andWhere('p.price <= :max')->setParameter('max', $c->maxPrice);
        }
        if (null !== $c->minRating) {
            $qb->andWhere('p.ratingAverage >= :rating')->setParameter('rating', $c->minRating);
        }
        if ($c->inStock) {
            $qb->andWhere('p.stock > 0');
        }
        // "Deals" filter: products with a "was" price higher than the current price.
        if ($c->deals) {
            $qb->andWhere('p.compareAtPrice IS NOT NULL AND p.compareAtPrice > p.price');
        }

        // SECURITY (rule 1): ORDER BY comes from a fixed whitelist (ProductSearchCriteria::SORTS), never from input.
        [$field, $direction] = $c->orderBy();
        $qb->orderBy($field, $direction)->addOrderBy('p.id', 'DESC')->addOrderBy('img.position', 'ASC')
            // Pagination: skip the previous pages and read one page only.
            ->setFirstResult(($c->page - 1) * ProductSearchCriteria::PER_PAGE)
            ->setMaxResults(ProductSearchCriteria::PER_PAGE);

        // Paginator also counts the total number of results (needed to draw the page links).
        return new Paginator($qb->getQuery(), fetchJoinCollection: true);
    }

    /**
     * Price bounds of the active catalogue (for the price filter placeholders).
     *
     * @return array{min: float, max: float}
     */
    public function priceBounds(): array
    {
        $row = $this->createQueryBuilder('p')
            ->select('MIN(p.price) AS min, MAX(p.price) AS max')
            ->andWhere('p.isActive = true')
            ->getQuery()->getSingleResult();

        // 0 for both when the catalogue is empty.
        return ['min' => (float) ($row['min'] ?? 0), 'max' => (float) ($row['max'] ?? 0)];
    }

    /**
     * Best sellers by units sold (non-cancelled orders), active products only.
     *
     * @return list<Product>
     */
    public function findBestSellers(int $limit = 8): array
    {
        // Step 1: ids of the most sold active products (sum of the quantities of sold order lines).
        $rows = $this->getEntityManager()->createQueryBuilder()
            ->select('IDENTITY(i.product) AS pid, SUM(i.quantity) AS units')
            ->from(OrderItem::class, 'i')
            ->innerJoin('i.product', 'p')
            ->andWhere('p.isActive = true')
            ->andWhere('i.status IN (:sold)')->setParameter('sold', self::SOLD)
            ->groupBy('i.product')
            ->orderBy('units', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()->getArrayResult();

        // Step 2: load these products, keeping the "most sold first" order.
        $ids = array_map(static fn (array $r) => (int) $r['pid'], $rows);
        $products = $this->findActiveByIds($ids);

        // Not enough sales yet (fresh install): complete with the best-rated products.
        if (count($products) < $limit) {
            $more = $this->createQueryBuilder('p')
                ->andWhere('p.isActive = true')
                ->orderBy('p.ratingAverage', 'DESC')->addOrderBy('p.createdAt', 'DESC')
                ->setMaxResults($limit * 2)->getQuery()->getResult();
            foreach ($more as $product) {
                if (count($products) >= $limit) {
                    break;
                }
                if (!in_array($product, $products, true)) {
                    $products[] = $product;
                }
            }
        }

        return $this->preloadImages($products);
    }

    /**
     * @param list<int> $ids
     *
     * @return list<Product> in the order of $ids
     */
    public function findActiveByIds(array $ids): array
    {
        if ([] === $ids) {
            return [];
        }
        // Load the products in one query...
        $found = $this->createQueryBuilder('p')
            ->andWhere('p.id IN (:ids)')->andWhere('p.isActive = true')
            ->setParameter('ids', $ids)
            ->getQuery()->getResult();
        // ...index them by id...
        $byId = [];
        foreach ($found as $p) {
            $byId[$p->getId()] = $p;
        }

        // ...and return them in the same order as $ids (missing or hidden ones are dropped).
        return array_values(array_filter(array_map(static fn (int $id) => $byId[$id] ?? null, $ids)));
    }

    /**
     * "You may also like": same category first, then anything else, excluding $product.
     *
     * @return list<Product>
     */
    public function findSimilar(Product $product, int $limit = 4): array
    {
        // First try: other active products of the same category, best rated first.
        $result = $this->createQueryBuilder('p')
            ->andWhere('p.isActive = true')->andWhere('p != :self')->andWhere('p.category = :cat')
            ->setParameter('self', $product)->setParameter('cat', $product->getCategory())
            ->orderBy('p.ratingAverage', 'DESC')->setMaxResults($limit)
            ->getQuery()->getResult();

        // Not enough? Complete with recent products of other categories.
        if (count($result) < $limit) {
            $more = $this->createQueryBuilder('p')
                ->andWhere('p.isActive = true')->andWhere('p != :self')->andWhere('p.category != :cat')
                ->setParameter('self', $product)->setParameter('cat', $product->getCategory())
                ->orderBy('p.createdAt', 'DESC')->setMaxResults($limit - count($result))
                ->getQuery()->getResult();
            $result = array_merge($result, $more);
        }

        return $this->preloadImages($result);
    }

    /**
     * The newest active products (home page "New arrivals").
     *
     * @return list<Product>
     */
    public function findLatestActive(int $limit = 8): array
    {
        return $this->preloadImages($this->createQueryBuilder('p')
            ->andWhere('p.isActive = true')
            ->orderBy('p.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult());
    }

    /**
     * Active products in stock that have a "was" price (home page "Deals").
     *
     * @return list<Product>
     */
    public function findDeals(int $limit = 4): array
    {
        return $this->preloadImages($this->createQueryBuilder('p')
            ->andWhere('p.isActive = true')->andWhere('p.stock > 0')
            ->andWhere('p.compareAtPrice IS NOT NULL AND p.compareAtPrice > p.price')
            ->orderBy('p.updatedAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()->getResult());
    }

    /** Number of active products of the whole site. */
    public function countActive(): int
    {
        return (int) $this->createQueryBuilder('p')->select('COUNT(p.id)')->andWhere('p.isActive = true')->getQuery()->getSingleScalarResult();
    }

    /** Number of active products of one seller. */
    public function countActiveBySeller(User $seller): int
    {
        return (int) $this->createQueryBuilder('p')
            ->select('COUNT(p.id)')
            ->andWhere('p.seller = :seller')->andWhere('p.isActive = true')
            ->setParameter('seller', $seller)
            ->getQuery()->getSingleScalarResult();
    }

    /**
     * Active products of $seller with fewer than $threshold units left (seller dashboard alert).
     *
     * @return list<Product>
     */
    public function findLowStock(User $seller, int $threshold = 5): array
    {
        return $this->preloadImages($this->createQueryBuilder('p')
            ->andWhere('p.seller = :seller')->andWhere('p.stock < :t')->andWhere('p.isActive = true')
            ->setParameter('seller', $seller)->setParameter('t', $threshold)
            ->orderBy('p.stock', 'ASC')
            ->getQuery()->getResult());
    }

    /**
     *
     * @param string|null   $search   part of a product name or seller email
     * @param string|null   $state    "active", "hidden" or null for both
     * @param Category|null $category limit to one category
     * @param User|null     $seller   limit to one seller
     *
     * @return QueryBuilder the query, not executed yet (the caller adds the pagination)
     * Admin moderation list (all products, active or not).
     */
    public function createAdminQueryBuilder(?string $search, ?string $state, ?Category $category = null, ?User $seller = null): QueryBuilder
    {
        $qb = $this->createQueryBuilder('p')
            ->innerJoin('p.seller', 's')->addSelect('s')
            ->innerJoin('p.category', 'c')->addSelect('c')
            ->orderBy('p.createdAt', 'DESC');
        // SECURITY: SQL injection - bound parameter; the % and _ typed by the admin are escaped (addcslashes).
        if (null !== $search && '' !== $search) {
            $qb->andWhere('(p.name LIKE :q OR s.email LIKE :q)')
                ->setParameter('q', '%'.addcslashes($search, '%_\\').'%');
        }
        // SECURITY: validation - only the two known values are accepted; anything else means "no filter".
        if ('active' === $state || 'hidden' === $state) {
            $qb->andWhere('p.isActive = :active')->setParameter('active', 'active' === $state);
        }
        if (null !== $category) {
            $qb->andWhere('p.category = :cat')->setParameter('cat', $category);
        }
        if (null !== $seller) {
            $qb->andWhere('p.seller = :seller')->setParameter('seller', $seller);
        }

        return $qb;
    }

    /**
     * Units sold per product id for a list of products (seller tables).
     *
     * @param list<Product> $products
     *
     * @return array<int, int>
     */
    public function salesCounts(array $products): array
    {
        if ([] === $products) {
            return [];
        }
        // Sum of the quantities sold per product, in one GROUP BY query.
        $rows = $this->getEntityManager()->createQueryBuilder()
            ->select('IDENTITY(i.product) AS pid, SUM(i.quantity) AS units')
            ->from(OrderItem::class, 'i')
            ->andWhere('i.product IN (:products)')->andWhere('i.status IN (:sold)')
            ->setParameter('products', $products)->setParameter('sold', self::SOLD)
            ->groupBy('i.product')
            ->getQuery()->getArrayResult();

        // Turn the rows into an array [product id => units sold].
        $counts = [];
        foreach ($rows as $r) {
            $counts[(int) $r['pid']] = (int) $r['units'];
        }

        return $counts;
    }

    /**
     * Loads the images of many products in ONE query (avoids N+1 queries when rendering product cards).
     *
     * @param list<Product> $products
     *
     * @return list<Product>
     */
    public function preloadImages(array $products): array
    {
        // The result is not used directly: Doctrine fills the images of the product objects already in memory.
        if ([] !== $products) {
            $this->createQueryBuilder('p')
                ->select('p', 'img')
                ->leftJoin('p.images', 'img')
                ->andWhere('p IN (:products)')->setParameter('products', $products)
                ->getQuery()->getResult();
        }

        return $products;
    }
}
