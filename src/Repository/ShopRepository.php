<?php

namespace App\Repository;

use App\Entity\Shop;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Shop>
 */
class ShopRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Shop::class);
    }

    public function findOneBySlug(string $slug): ?Shop
    {
        return $this->createQueryBuilder('s')
            ->innerJoin('s.owner', 'o')->addSelect('o')
            ->andWhere('s.slug = :slug')->setParameter('slug', $slug)
            ->getQuery()->getOneOrNullResult();
    }

    /**
     * A free slug based on $base: "atelier", "atelier-2", "atelier-3"...
     */
    public function uniqueSlug(string $base): string
    {
        $base = Shop::slugify($base);
        if (in_array($base, Shop::RESERVED_SLUGS, true) || mb_strlen($base) < 3) {
            $base = 'shop-'.$base;
        }
        $slug = $base;
        for ($i = 2; null !== $this->findOneBy(['slug' => $slug]); ++$i) {
            $slug = $base.'-'.$i;
        }

        return $slug;
    }

    /**
     * @return list<Shop>
     */
    public function findFeatured(int $limit = 4): array
    {
        return $this->createQueryBuilder('s')->orderBy('s.createdAt', 'ASC')->setMaxResults($limit)->getQuery()->getResult();
    }
}
