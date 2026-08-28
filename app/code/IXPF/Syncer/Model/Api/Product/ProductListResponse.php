<?php

namespace IXPF\Syncer\Model\Api\Product;

use IXPF\Syncer\Api\Product\ProductListResponseApi;
use Magento\Framework\DataObject;

class ProductListResponse extends DataObject implements ProductListResponseApi
{

    /**
     * @return array|ProductResponse[]
     */
    public function getItems(): array
    {
        return $this->_getData('items');
    }

    /**
     * @return int
     */
    public function getTotalCount(): int
    {
        return $this->_getData('total_count');
    }


    public function getPageSize(): int
    {
       return $this->_getData('page_size');
    }
}
