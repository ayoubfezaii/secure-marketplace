<?php

/**
 * FILE: src/Repository/ShopRepository.php
 * WHAT: all the database queries about shops.
 * USED BY: ShopController (public shop page, everyone), HomeController, Seller\ShopController (seller),
 *   AccountManager (creates the shop of a new seller).
 */

namespace App\Repository;

use App\Entity\Shop;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Shop>
 */
class ShopRepository extends ServiceEntityRepository
{
    /** Tells Doctrine that this repository works on the Shop entity. */
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Shop::class);
    }

    /**
     * Finds a shop by the slug found in the URL, with its owner, or null.
     * SECURITY: SQL injection - the slug is a bound parameter (:slug).
     */
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
        // Clean the text into a URL-safe slug.
        $base = Shop::slugify($base);
        // Reserved or too short: add a prefix.
        if (in_array($base, Shop::RESERVED_SLUGS, true) || mb_strlen($base) < 3) {
            $base = 'shop-'.$base;
        }
        // Try "base", then "base-2", "base-3"... until a free slug is found.
        $slug = $base;
        for ($i = 2; null !== $this->findOneBy(['slug' => $slug]); ++$i) {
            $slug = $base.'-'.$i;
        }

        return $slug;
    }

    /**
     * The first shops created (home page "Featured shops").
     *
     * @return list<Shop>
     */
    public function findFeatured(int $limit = 4): array
    {
        return $this->createQueryBuilder('s')->orderBy('s.createdAt', 'ASC')->setMaxResults($limit)->getQuery()->getResult();
    }
}
