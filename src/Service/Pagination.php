<?php

/**
 * FILE: src/Service/Pagination.php
 * WHAT: small helper that cuts a query into pages (?page=2).
 * ROLE IN THE PROJECT: shared by all the list pages of the dashboards.
 * USED BY: buyer, seller and admin list controllers; templates/_partials/pager.html.twig draws the links.
 */

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
     * Private: objects are created with fromQueryBuilder() only.
     *
     * @param int $page  current page number (starts at 1)
     * @param int $pages total number of pages (at least 1)
     * @param int $total total number of rows
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
     * Reads ?page= from the URL and runs the query for that page only.
     *
     * @param QueryBuilder $qb      the list query, without limit
     * @param Request      $request the HTTP request
     * @param int          $perPage rows per page
     *
     * @return self<mixed>
     */
    public static function fromQueryBuilder(QueryBuilder $qb, Request $request, int $perPage = 20): self
    {
        // SECURITY (rule 15): page is a positive bounded integer; anything else = page 1.
        $page = filter_var($request->query->getString('page', '1'), \FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 100000]]) ?: 1;

        // Skip the rows of the previous pages and read one page.
        $qb->setFirstResult(($page - 1) * $perPage)->setMaxResults($perPage);
        // Paginator runs the page query and a COUNT query for the total.
        $paginator = new Paginator($qb->getQuery(), fetchJoinCollection: false);
        $total = count($paginator);

        return new self($paginator, $page, max(1, (int) ceil($total / $perPage)), $total);
    }
}
