<?php

/**
 * FILE: src/Repository/CategoryRepository.php
 * WHAT: all the database queries about categories.
 * ROLE IN THE PROJECT: gives the category list to the catalogue filters, the home page and the admin panel.
 * USED BY: CatalogController, HomeController (everyone), ProductFormType (seller), Admin\CategoryController (admin).
 */

namespace App\Repository;

use App\Entity\Category;
use App\Entity\Product;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Category>
 */
class CategoryRepository extends ServiceEntityRepository
{
    /** Tells Doctrine that this repository works on the Category entity. */
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Category::class);
    }

    /**
     * All categories sorted by position, then by name.
     *
     * @return list<Category>
     */
    public function findAllOrdered(): array
    {
        return $this->createQueryBuilder('c')->orderBy('c.position', 'ASC')->addOrderBy('c.name', 'ASC')->getQuery()->getResult();
    }

    /** Finds a category by its slug (the text used in URLs), or null. */
    public function findOneBySlug(string $slug): ?Category
    {
        // SECURITY (rule 1): bound parameter; the slug format is checked by the caller too.
        return $this->findOneBy(['slug' => $slug]);
    }

    /**
     * Active product count per category id (for the admin list and the storefront tiles).
     *
     * @return array<int, int>
     */
    public function countProducts(bool $activeOnly = false): array
    {
        // Count the products of each category with one GROUP BY query.
        $qb = $this->getEntityManager()->createQueryBuilder()
            ->select('IDENTITY(p.category) AS cid, COUNT(p.id) AS cnt')
            ->from(Product::class, 'p')
            ->groupBy('p.category');
        // Optionally ignore hidden products.
        if ($activeOnly) {
            $qb->andWhere('p.isActive = true');
        }

        // Turn the rows into an array [category id => number of products].
        $counts = [];
        foreach ($qb->getQuery()->getArrayResult() as $row) {
            $counts[(int) $row['cid']] = (int) $row['cnt'];
        }

        return $counts;
    }
}
