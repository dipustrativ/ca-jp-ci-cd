<?php

declare(strict_types=1);

namespace IXPF\Syncer\Api\Data;

use IXPF\Syncer\Model\Reviews\ReviewItem;

/**
 * One page of reviews, sorted by created_at descending.
 *
 * @api
 */
interface ReviewListInterface
{
    /**
     * @return ReviewItem[]
     */
    public function getItems(): array;

    /**
     * Total matching the applied filter, not the page size.
     *
     * @return int
     */
    public function getTotalCount(): int;

    /**
     * @return int 1-based
     */
    public function getPage(): int;

    /**
     * @return int
     */
    public function getPageSize(): int;

    /**
     * @return int
     */
    public function getPageCount(): int;
}
