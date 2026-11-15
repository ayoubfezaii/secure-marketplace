<?php

/**
 * FILE: src/Repository/ProductImageRepository.php
 * WHAT: repository of the product images. It only uses the standard Doctrine methods.
 * USED BY: Seller\ProductController when a seller deletes an image.
 */

namespace App\Repository;

use App\Entity\ProductImage;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ProductImage>
 */
class ProductImageRepository extends ServiceEntityRepository
{
    /** Tells Doctrine that this repository works on the ProductImage entity. */
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ProductImage::class);
    }
}
