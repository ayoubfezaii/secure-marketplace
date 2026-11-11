<?php

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
    public const MAX_Q = 100;

    /**
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
     * @param array<string, string> $sorts sort key => DQL field (whitelist)
     */
    public static function fromRequest(Request $request, array $sorts, string $defaultSort, string $defaultDir = 'DESC'): self
    {
        $q = mb_substr(trim($request->query->getString('q')), 0, self::MAX_Q);
        $sort = $request->query->getString('sort', $defaultSort);
        $sort = array_key_exists($sort, $sorts) ? $sort : $defaultSort;
        $dir = strtoupper($request->query->getString('dir', $defaultDir));
        $dir = in_array($dir, ['ASC', 'DESC'], true) ? $dir : $defaultDir;

        return new self($q, $sort, $dir, $sorts);
    }

    /** Applies the (whitelisted) ORDER BY to a query builder. */
    public function applySort(QueryBuilder $qb): QueryBuilder
    {
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
