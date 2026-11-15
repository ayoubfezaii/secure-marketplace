<?php

/**
 * FILE: src/Dto/ProductSearchCriteria.php
 * WHAT: DTO that holds the cleaned search filters of the catalogue (text, category, prices, rating, sort, page).
 * ROLE IN THE PROJECT: the "filter" between the URL and the SQL query. With ProductRepository::search() it is
 *   the defence against SQL injection in the search.
 * USED BY: CatalogController and ShopController (everyone), ProductRepository::search().
 */

namespace App\Dto;

use App\Service\Money;
use Symfony\Component\HttpFoundation\Request;

/**
 * Catalogue search parameters, normalised from the query string (rule 15).
 *
 * SECURITY: every value is whitelisted or clamped HERE, before it reaches the repository. The sort value in
 * particular is mapped to a fixed DQL ORDER BY: ORDER BY can't use bound parameters, so a whitelist is the only
 * safe way to make it user-selectable (rule 1). The category slug is format-checked here and then resolved to an
 * existing Category by the controller (unknown slug = no filter).
 */
final class ProductSearchCriteria
{
    // Products per page and maximum length of the search text.
    public const PER_PAGE = 12;
    public const MAX_QUERY_LENGTH = 100;

    /** sort key => [DQL field, direction]. Nothing else can ever reach ORDER BY. */
    public const SORTS = [
        'newest' => ['p.createdAt', 'DESC'],
        'price_asc' => ['p.price', 'ASC'],
        'price_desc' => ['p.price', 'DESC'],
        'rating' => ['p.ratingAverage', 'DESC'],
        'name' => ['p.name', 'ASC'],
    ];

    // Labels shown in the "Sort by" dropdown (same keys as SORTS).
    public const SORT_LABELS = [
        'newest' => 'Newest',
        'price_asc' => 'Price: low to high',
        'price_desc' => 'Price: high to low',
        'rating' => 'Best rated',
        'name' => 'Name (A-Z)',
    ];

    /** Allowed "minimum rating" filter values. */
    public const RATINGS = [4, 3, 2, 1];

    /**
     * All properties are read-only: once created, the criteria cannot be changed.
     * Prices are strings (decimal text), like everywhere else in the project.
     */
    public function __construct(
        public readonly string $query = '',
        public readonly ?string $category = null,
        public readonly ?string $minPrice = null,
        public readonly ?string $maxPrice = null,
        public readonly string $sort = 'newest',
        public readonly int $page = 1,
        public readonly ?int $minRating = null,
        public readonly bool $inStock = false,
        public readonly bool $deals = false,
    ) {
    }

    /**
     * Builds the criteria from the URL parameters (?q=...&category=...&min=...&max=...&sort=...&page=...).
     * Each value is cleaned; a wrong value is replaced by a safe default instead of causing an error.
     *
     * @param Request $request the HTTP request
     */
    public static function fromRequest(Request $request): self
    {
        // $q = the parameters of the query string.
        $q = $request->query;

        // Length cap: avoids huge LIKE patterns (cheap DoS).
        $query = mb_substr(trim($q->getString('q')), 0, self::MAX_QUERY_LENGTH);

        $category = $q->getString('category');
        // SECURITY: slug format only; anything else is ignored.
        $category = 1 === preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $category) && strlen($category) <= 60 ? $category : null;

        // SECURITY: SQL injection - the sort key must be one of the keys of SORTS; otherwise "newest" is used.
        $sort = $q->getString('sort', 'newest');
        $sort = array_key_exists($sort, self::SORTS) ? $sort : 'newest';

        // SECURITY: validation - the page must be a whole number between 1 and 10000.
        $page = filter_var($q->getString('page', '1'), \FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 10000]]);

        // Minimum rating: only 1, 2, 3 or 4 are accepted.
        $rating = filter_var($q->getString('rating'), \FILTER_VALIDATE_INT);
        $rating = in_array($rating, self::RATINGS, true) ? $rating : null;

        return new self(
            $query,
            $category,
            self::price($q->getString('min')),
            self::price($q->getString('max')),
            $sort,
            false === $page ? 1 : $page,
            $rating,
            '1' === $q->getString('stock'),
            '1' === $q->getString('deals'),
        );
    }

    /**
     * The ORDER BY of the chosen sort: [DQL field, direction], taken from the SORTS whitelist.
     *
     * @return array{0: string, 1: string}
     */
    public function orderBy(): array
    {
        return self::SORTS[$this->sort];
    }

    /**
     * Query parameters for building pagination/sort links (only normalised values, never the raw input).
     *
     * @param array<string, mixed> $override
     *
     * @return array<string, string|int>
     */
    public function toQuery(array $override = []): array
    {
        $params = array_merge([
            'q' => $this->query,
            'category' => $this->category,
            'min' => $this->minPrice,
            'max' => $this->maxPrice,
            'rating' => $this->minRating,
            'stock' => $this->inStock ? '1' : null,
            'deals' => $this->deals ? '1' : null,
            'sort' => 'newest' !== $this->sort ? $this->sort : null,
        ], $override);

        return array_filter($params, static fn ($v) => null !== $v && '' !== $v);
    }

    /** Returns a copy of the criteria with another category (objects are read-only, so a new one is created). */
    public function withCategory(?string $category): self
    {
        return new self($this->query, $category, $this->minPrice, $this->maxPrice, $this->sort, $this->page, $this->minRating, $this->inStock, $this->deals);
    }

    /** True when at least one filter is active (used to show the "Clear filters" link). */
    public function hasFilters(): bool
    {
        return '' !== $this->query || null !== $this->category || null !== $this->minPrice || null !== $this->maxPrice
            || null !== $this->minRating || $this->inStock || $this->deals;
    }

    /**
     * Cleans a price filter typed by the visitor.
     *
     * @return string|null a decimal string such as "35.500", or null when the value is not a valid price
     */
    private static function price(string $raw): ?string
    {
        // Accept a comma as decimal separator.
        $raw = str_replace(',', '.', trim($raw));
        // SECURITY: strict decimal format and range; anything else is ignored.
        if (1 !== preg_match(Money::PATTERN, $raw) || (float) $raw > Money::MAX_PRICE) {
            return null;
        }

        return $raw;
    }
}
