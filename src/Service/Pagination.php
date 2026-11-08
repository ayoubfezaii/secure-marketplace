<?php

namespace App\Service;

use Doctrine\ORM\QueryBuilder;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Symfony\Component\HttpFoundation\Request;

/**
 * Small pagination helper for admin lists.
 *
 * @template T
 */
final class Pagination
{
    /**
     * @param Paginator<T> $items
     */
    private function __construct(
        public readonly Paginator $items,
        public readonly int $page,
        public readonly int $pages,
        public readonly int $total,
    ) {
    }

    /**
     * @return self<mixed>
     */
    public static function fromQueryBuilder(QueryBuilder $qb, Request $request, int $perPage = 20): self
    {
        // SECURITY (rule 15): page is a positive bounded integer; anything else = page 1.
        $page = filter_var($request->query->getString('page', '1'), \FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 100000]]) ?: 1;

        $qb->setFirstResult(($page - 1) * $perPage)->setMaxResults($perPage);
        $paginator = new Paginator($qb->getQuery(), fetchJoinCollection: false);
        $total = count($paginator);

        return new self($paginator, $page, max(1, (int) ceil($total / $perPage)), $total);
    }
}
