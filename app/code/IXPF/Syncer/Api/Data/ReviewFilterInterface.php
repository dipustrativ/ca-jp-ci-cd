<?php

declare(strict_types=1);

namespace IXPF\Syncer\Api\Data;

/**
 * @api
 */
interface ReviewFilterInterface
{
    public const SORT_NEWEST  = 'newest';
    public const SORT_HIGHEST = 'highest';
    public const SORT_LOWEST  = 'lowest';

    /**
     * @return int|null 1-5, or null for no rating filter
     */
    public function getRating(): ?int;

    /**
     * @return bool|null true for reviews with fetched photos only, null for no filter
     */
    public function getHasPhotos(): ?bool;

    /**
     * @return bool|null true for reviews with a written body only, null for no filter
     */
    public function getWithText(): ?bool;

    /**
     * @return int 1-based
     */
    public function getPage(): int;

    /**
     * @return int
     */
    public function getPageSize(): int;

    /**
     * One of the SORT_* constants. Never null - an unrecognised value resolves to SORT_NEWEST.
     *
     * @return string
     */
    public function getSort(): string;
}
