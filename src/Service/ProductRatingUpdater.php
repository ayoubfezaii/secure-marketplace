<?php

namespace App\Service;

use App\Entity\Product;
use App\Entity\Review;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Recomputes the denormalised rating (average + count) of a product from its reviews.
 * Called after a review is created, edited or deleted. DQL with bound parameters only (rule 1).
 */
final class ProductRatingUpdater
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    public function refresh(Product $product): void
    {
        $row = $this->em->createQueryBuilder()
            ->select('AVG(r.rating) AS avg, COUNT(r.id) AS cnt')
            ->from(Review::class, 'r')
            ->where('r.product = :p')->setParameter('p', $product)
            ->getQuery()->getSingleResult();

        $product->setRatingSummary((float) ($row['avg'] ?? 0), (int) $row['cnt']);
    }
}
