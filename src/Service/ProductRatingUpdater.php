<?php

/**
 * FILE: src/Service/ProductRatingUpdater.php
 * WHAT: service that recalculates the average rating and review count stored on a product.
 * ROLE IN THE PROJECT: keeps Product::ratingAverage / ratingCount in sync with the reviews.
 * USED BY: ReviewController (create / delete) and Buyer\ReviewController (edit).
 */

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
    /**
     * @param EntityManagerInterface $em Doctrine, to run the query
     */
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    /**
     * Reads the average and the count from the reviews and writes them on the product.
     * The caller must flush() afterwards to save.
     */
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
