<?php

/**
 * FILE: src/Repository/OrderItemRepository.php
 * WHAT: repository of the order lines. It only uses the standard Doctrine methods (findOneBy...).
 * USED BY: the seller order pages and the statistics services.
 */

namespace App\Repository;

use App\Entity\OrderItem;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<OrderItem>
 */
class OrderItemRepository extends ServiceEntityRepository
{
    /** Tells Doctrine that this repository works on the OrderItem entity. */
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, OrderItem::class);
    }
}
