<?php

/**
 * FILE: src/Repository/ReviewReplyRepository.php
 * WHAT: repository of the seller replies. It only uses the standard Doctrine methods.
 * USED BY: Seller\ReviewController (seller).
 */

namespace App\Repository;

use App\Entity\ReviewReply;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ReviewReply>
 */
class ReviewReplyRepository extends ServiceEntityRepository
{
    /** Tells Doctrine that this repository works on the ReviewReply entity. */
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ReviewReply::class);
    }
}
