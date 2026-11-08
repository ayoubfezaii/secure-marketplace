<?php

namespace App\Repository;

use App\Dto\ProductSearchCriteria;
use App\Entity\Product;
use Doctrine\ORM\QueryBuilder;
use Doctrine\ORM\Tools\Pagination\Paginator;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * SECURITY (rule 1): every query uses QueryBuilder/DQL with bound parameters. No string concatenation of input.
 *
 * @extends ServiceEntityRepository<Product>
 */
class ProductRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Product::class);
    }

    /**
     * @return list<Product>
     */
    public function findBySeller(User $seller): array
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.seller = :seller')
            ->setParameter('seller', $seller)
            ->orderBy('p.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function findOneByUuid(string $uuid): ?Product
    {
        return $this->findOneBy(['uuid' => $uuid]);
    }

    /**
     * Public catalogue search. Only active products.
     *
     * @return Paginator<Product>
     */
    public function search(ProductSearchCriteria $c): Paginator
    {
        $qb = $this->createQueryBuilder('p')
            ->innerJoin('p.seller', 's')->addSelect('s')
            ->andWhere('p.isActive = true');

        if ('' !== $c->query) {
            // SECURITY (rule 1): the text is a bound parameter. LIKE wildcards (% and _) typed by the user are
            // escaped too, so "%" can't be used to dump everything or build slow patterns.
            $qb->andWhere('(p.name LIKE :q OR p.description LIKE :q)')
                ->setParameter('q', '%'.addcslashes($c->query, '%_\\').'%');
        }
        if (null !== $c->category) {
            $qb->andWhere('p.category = :category')->setParameter('category', $c->category);
        }
        if (null !== $c->minPrice) {
            $qb->andWhere('p.price >= :min')->setParameter('min', $c->minPrice);
        }
        if (null !== $c->maxPrice) {
            $qb->andWhere('p.price <= :max')->setParameter('max', $c->maxPrice);
        }

        // SECURITY (rule 1): ORDER BY comes from a fixed whitelist (ProductSearchCriteria::SORTS), never from input.
        [$field, $direction] = $c->orderBy();
        $qb->orderBy($field, $direction)->addOrderBy('p.id', 'DESC')
            ->setFirstResult(($c->page - 1) * ProductSearchCriteria::PER_PAGE)
            ->setMaxResults(ProductSearchCriteria::PER_PAGE);

        return new Paginator($qb->getQuery(), fetchJoinCollection: false);
    }

    public function countActiveBySeller(User $seller): int
    {
        return (int) $this->createQueryBuilder('p')
            ->select('COUNT(p.id)')
            ->andWhere('p.seller = :seller')->andWhere('p.isActive = true')
            ->setParameter('seller', $seller)
            ->getQuery()->getSingleScalarResult();
    }

    /**
     * @return list<Product>
     */
    public function findLowStock(User $seller, int $threshold = 5): array
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.seller = :seller')->andWhere('p.stock < :t')->andWhere('p.isActive = true')
            ->setParameter('seller', $seller)->setParameter('t', $threshold)
            ->orderBy('p.stock', 'ASC')
            ->getQuery()->getResult();
    }

    /**
     * Admin moderation list (all products, active or not).
     */
    public function createAdminQueryBuilder(?string $search, ?string $state): QueryBuilder
    {
        $qb = $this->createQueryBuilder('p')
            ->innerJoin('p.seller', 's')->addSelect('s')
            ->orderBy('p.createdAt', 'DESC');
        if (null !== $search && '' !== $search) {
            $qb->andWhere('(p.name LIKE :q OR s.email LIKE :q)')
                ->setParameter('q', '%'.addcslashes($search, '%_\\').'%');
        }
        if ('active' === $state || 'hidden' === $state) {
            $qb->andWhere('p.isActive = :active')->setParameter('active', 'active' === $state);
        }

        return $qb;
    }

    /**
     * @return list<Product>
     */
    public function findLatestActive(int $limit = 8): array
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.isActive = true')
            ->orderBy('p.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
