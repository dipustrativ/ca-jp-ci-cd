<?php

namespace IXPF\Syncer\Api\Product;

interface ProductListResponseApi
{
    /**
     * @return \IXPF\Syncer\Api\Product\ProductResponseApi[]
     */
    public function getItems(): array;

    /**
     * @return int
     */
    public function getTotalCount(): int;

    /**
     * @return int
     */
    public function getPageSize(): int;

}
