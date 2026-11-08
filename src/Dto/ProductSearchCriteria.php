<?php

namespace App\Dto;

use App\Entity\Product;
use Symfony\Component\HttpFoundation\Request;

/**
 * Catalogue search parameters, normalised from the query string (rule 15).
 *
 * SECURITY: every value is whitelisted or clamped HERE, before it reaches the repository. The sort value in
 * particular is mapped to a fixed DQL ORDER BY: ORDER BY can't use bound parameters, so a whitelist is the only
 * safe way to make it user-selectable (rule 1).
 */
final class ProductSearchCriteria
{
    public const PER_PAGE = 12;
    public const MAX_QUERY_LENGTH = 100;

    /** sort key => [DQL field, direction]. Nothing else can ever reach ORDER BY. */
    public const SORTS = [
        'newest' => ['p.createdAt', 'DESC'],
        'price_asc' => ['p.price', 'ASC'],
        'price_desc' => ['p.price', 'DESC'],
        'name' => ['p.name', 'ASC'],
    ];

    public const SORT_LABELS = [
        'newest' => 'Newest',
        'price_asc' => 'Price: low to high',
        'price_desc' => 'Price: high to low',
        'name' => 'Name (A-Z)',
    ];

    public function __construct(
        public readonly string $query = '',
        public readonly ?string $category = null,
        public readonly ?string $minPrice = null,
        public readonly ?string $maxPrice = null,
        public readonly string $sort = 'newest',
        public readonly int $page = 1,
    ) {
    }

    public static function fromRequest(Request $request): self
    {
        $q = $request->query;

        $query = trim($q->getString('q'));
        // Length cap: avoids huge LIKE patterns (cheap DoS).
        $query = mb_substr($query, 0, self::MAX_QUERY_LENGTH);

        $category = $q->getString('category');
        $category = array_key_exists($category, Product::CATEGORIES) ? $category : null;

        $sort = $q->getString('sort', 'newest');
        $sort = array_key_exists($sort, self::SORTS) ? $sort : 'newest';

        $page = filter_var($q->getString('page', '1'), \FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 10000]]);

        return new self(
            $query,
            $category,
            self::price($q->getString('min')),
            self::price($q->getString('max')),
            $sort,
            false === $page ? 1 : $page,
        );
    }

    /**
     * @return array{0: string, 1: string}
     */
    public function orderBy(): array
    {
        return self::SORTS[$this->sort];
    }

    /**
     * Query parameters for building pagination/sort links (only normalised values, never the raw input).
     *
     * @return array<string, string|int>
     */
    public function toQuery(): array
    {
        return array_filter([
            'q' => $this->query,
            'category' => $this->category,
            'min' => $this->minPrice,
            'max' => $this->maxPrice,
            'sort' => 'newest' !== $this->sort ? $this->sort : null,
        ], static fn ($v) => null !== $v && '' !== $v);
    }

    public function hasFilters(): bool
    {
        return '' !== $this->query || null !== $this->category || null !== $this->minPrice || null !== $this->maxPrice;
    }

    private static function price(string $raw): ?string
    {
        $raw = str_replace(',', '.', trim($raw));
        // SECURITY: strict decimal format and range; anything else is ignored.
        if (1 !== preg_match('/^\d{1,6}(\.\d{1,2})?$/', $raw) || (float) $raw > 100000) {
            return null;
        }

        return $raw;
    }
}
