<?php

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
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Category::class);
    }

    /**
     * @return list<Category>
     */
    public function findAllOrdered(): array
    {
        return $this->createQueryBuilder('c')->orderBy('c.position', 'ASC')->addOrderBy('c.name', 'ASC')->getQuery()->getResult();
    }

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
        $qb = $this->getEntityManager()->createQueryBuilder()
            ->select('IDENTITY(p.category) AS cid, COUNT(p.id) AS cnt')
            ->from(Product::class, 'p')
            ->groupBy('p.category');
        if ($activeOnly) {
            $qb->andWhere('p.isActive = true');
        }

        $counts = [];
        foreach ($qb->getQuery()->getArrayResult() as $row) {
            $counts[(int) $row['cid']] = (int) $row['cnt'];
        }

        return $counts;
    }
}
