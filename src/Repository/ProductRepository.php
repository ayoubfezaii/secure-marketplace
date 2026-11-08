<?php

namespace App\Repository;

use App\Entity\Product;
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
}
