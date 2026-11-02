<?php

declare(strict_types=1);

namespace IXPF\Syncer\Model\Reviews;

use IXPF\Syncer\Api\Data\ReviewFilterInterface;

/**
 * Values are clamped here rather than trusted, because the eventual HTTP wrapper will pass
 * query parameters straight through.
 */
class ReviewFilter implements ReviewFilterInterface
{
    public const MAX_PAGE_SIZE = 100;

    public const SORTS = [
        self::SORT_NEWEST,
        self::SORT_HIGHEST,
        self::SORT_LOWEST,
    ];

    private ?int $rating;
    private ?bool $hasPhotos;
    private int $page;
    private int $pageSize;
    private string $sort;
    private ?bool $withText;

    // withText is last on purpose: the existing positional callers pass up to $sort, so appending
    // keeps them working rather than silently shifting a page size into a boolean.
    public function __construct(
        ?int $rating = null,
        ?bool $hasPhotos = null,
        int $page = 1,
        int $pageSize = 10,
        string $sort = self::SORT_NEWEST,
        ?bool $withText = null
    ) {
        $this->rating    = ($rating !== null && $rating >= 1 && $rating <= 5) ? $rating : null;
        $this->hasPhotos = $hasPhotos;
        $this->withText  = $withText;
        $this->page      = max(1, $page);
        $this->pageSize  = min(self::MAX_PAGE_SIZE, max(1, $pageSize));
        // Unrecognised sorts fall back rather than throw: the value arrives from a query string,
        // and a bad one should show the default order, not break the drawer.
        $this->sort      = in_array($sort, self::SORTS, true) ? $sort : self::SORT_NEWEST;
    }

    public function getRating(): ?int
    {
        return $this->rating;
    }

    public function getHasPhotos(): ?bool
    {
        return $this->hasPhotos;
    }

    public function getWithText(): ?bool
    {
        return $this->withText;
    }

    public function getPage(): int
    {
        return $this->page;
    }

    public function getPageSize(): int
    {
        return $this->pageSize;
    }

    public function getSort(): string
    {
        return $this->sort;
    }

    /**
     * Stable, collision-free fragment for the result cache key.
     *
     * The sort has to be in here. It changes which rows a page contains without changing any
     * other value, so leaving it out would serve one sort's page from another's cache entry.
     */
    public function getCacheFragment(): string
    {
        return sprintf(
            'r%s_p%s_t%s_%d_%d_%s',
            $this->rating ?? 'x',
            $this->hasPhotos === null ? 'x' : ($this->hasPhotos ? '1' : '0'),
            $this->withText === null ? 'x' : ($this->withText ? '1' : '0'),
            $this->page,
            $this->pageSize,
            $this->sort
        );
    }

    public function isUnfiltered(): bool
    {
        return $this->rating === null && $this->hasPhotos === null && $this->withText === null;
    }
}
