<?php

declare(strict_types=1);

namespace IXPF\Syncer\Model\ResourceModel\IxpfProductBulkUpdateQueue\Grid;

use Magento\Framework\View\Element\UiComponent\DataProvider\SearchResult;

class Collection extends SearchResult
{
    protected function _initSelect()
    {
        parent::_initSelect();

        $this->getSelect()
            ->order('main_table.entity_id DESC');

        return $this;
    }
}

