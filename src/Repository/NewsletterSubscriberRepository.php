<?php

/**
 * FILE: src/Repository/NewsletterSubscriberRepository.php
 * WHAT: repository of the newsletter subscribers. It only uses the standard Doctrine methods (findOneBy...).
 * USED BY: NewsletterController (any visitor).
 */

namespace App\Repository;

use App\Entity\NewsletterSubscriber;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<NewsletterSubscriber>
 */
class NewsletterSubscriberRepository extends ServiceEntityRepository
{
    /** Tells Doctrine that this repository works on the NewsletterSubscriber entity. */
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, NewsletterSubscriber::class);
    }
}
