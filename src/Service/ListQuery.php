<?php

/**
 * FILE: src/Service/ListQuery.php
 * WHAT: small object that reads and cleans the search / sort parameters of a table page (?q=...&sort=...&dir=...).
 * ROLE IN THE PROJECT: shared by all dashboard tables. It is the defence against SQL injection through ORDER BY.
 * USED BY: Seller\ProductController, Admin\UserController, Admin\ModerationController (seller, admin).
 */

namespace App\Service;

use Doctrine\ORM\QueryBuilder;
use Symfony\Component\HttpFoundation\Request;

/**
 * Search + sort + page parameters of a dashboard table, normalised from the query string.
 *
 * SECURITY (rules 1, 15):
 *  - "sort" must be a KEY of the whitelist given by the controller; it is mapped to a fixed DQL field. ORDER BY
 *    can't use bound parameters, so a whitelist is the only safe way to make it user-selectable.
 *  - "dir" is ASC or DESC, nothing else.
 *  - "q" is trimmed and capped at 100 characters; the repositories bind it as a parameter and escape LIKE
 *    wildcards (see like()).
 */
final class ListQuery
{
    // Maximum length of the search text.
    public const MAX_Q = 100;

    /**
     * Private: objects are created with fromRequest() only.
     *
     * @param string $q    cleaned search text
     * @param string $sort chosen sort key (always a key of $sorts)
     * @param string $dir  "ASC" or "DESC"
     * @param array<string, string> $sorts sort key => DQL field
     */
    private function __construct(
        public readonly string $q,
        public readonly string $sort,
        public readonly string $dir,
        private readonly array $sorts,
    ) {
    }

    /**
     * Builds the object from the URL parameters.
     *
     * @param Request $request     the HTTP request
     * @param string  $defaultSort sort key used when the URL gives none or an unknown one
     * @param string  $defaultDir  direction used when the URL gives none or a wrong one
     * @param array<string, string> $sorts sort key => DQL field (whitelist)
     */
    public static function fromRequest(Request $request, array $sorts, string $defaultSort, string $defaultDir = 'DESC'): self
    {
        // Search text: trimmed and cut to 100 characters.
        $q = mb_substr(trim($request->query->getString('q')), 0, self::MAX_Q);
        // SECURITY: SQL injection - "sort" is only accepted if it is a key of the whitelist.
        $sort = $request->query->getString('sort', $defaultSort);
        $sort = array_key_exists($sort, $sorts) ? $sort : $defaultSort;
        // SECURITY: SQL injection - "dir" can only be ASC or DESC.
        $dir = strtoupper($request->query->getString('dir', $defaultDir));
        $dir = in_array($dir, ['ASC', 'DESC'], true) ? $dir : $defaultDir;

        return new self($q, $sort, $dir, $sorts);
    }

    /** Applies the (whitelisted) ORDER BY to a query builder. */
    public function applySort(QueryBuilder $qb): QueryBuilder
    {
        // The column comes from the whitelist array (written by the developer), never from the URL text.
        return $qb->orderBy($this->sorts[$this->sort], $this->dir);
    }

    /** LIKE pattern with the user's wildcards escaped (bind it with setParameter). */
    public static function like(string $text): string
    {
        return '%'.addcslashes($text, '%_\\').'%';
    }

    /**
     * Normalised parameters to rebuild links (sorting / pagination), never the raw request.
     *
     * @return array<string, string>
     */
    public function params(): array
    {
        return array_filter(['q' => $this->q, 'sort' => $this->sort, 'dir' => $this->dir], static fn ($v) => '' !== $v);
    }
}
