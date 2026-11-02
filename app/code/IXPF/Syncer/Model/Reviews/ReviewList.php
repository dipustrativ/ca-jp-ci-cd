<?php

declare(strict_types=1);

namespace IXPF\Syncer\Model\Reviews;

use IXPF\Syncer\Api\Data\ReviewListInterface;

class ReviewList implements ReviewListInterface
{
    /**
     * @param ReviewItem[] $items
     */
    public function __construct(
        private readonly array $items = [],
        private readonly int $totalCount = 0,
        private readonly int $page = 1,
        private readonly int $pageSize = 10
    ) {
    }

    public function getItems(): array
    {
        return $this->items;
    }

    public function getTotalCount(): int
    {
        return $this->totalCount;
    }

    public function getPage(): int
    {
        return $this->page;
    }

    public function getPageSize(): int
    {
        return $this->pageSize;
    }

    public function getPageCount(): int
    {
        return $this->pageSize > 0 ? (int)ceil($this->totalCount / $this->pageSize) : 0;
    }
}
