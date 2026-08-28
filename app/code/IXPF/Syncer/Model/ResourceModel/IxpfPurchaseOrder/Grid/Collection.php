<?php

declare(strict_types=1);

namespace IXPF\Syncer\Model\ResourceModel\IxpfPurchaseOrder\Grid;

use Magento\Framework\View\Element\UiComponent\DataProvider\SearchResult;
use Zend_Db_Expr;

class Collection extends SearchResult
{
    protected function _initSelect()
    {
        parent::_initSelect();

        $this->getSelect()
            ->columns([
                'total_payload' => new Zend_Db_Expr('COUNT(DISTINCT main_table.entity_id)'),

                'total_products' => new Zend_Db_Expr("
            (SELECT COUNT(*)
             FROM ixpf_purchase_order_items items
             WHERE items.ixpf_purchase_id = main_table.ixpf_purchase_id)
        "),

                'total_success_products' => new Zend_Db_Expr("
            (SELECT COUNT(*)
             FROM ixpf_purchase_order_items items
             WHERE items.ixpf_purchase_id = main_table.ixpf_purchase_id
             AND items.process_status IN ('completed', 'ready_for_ongoing'))
        "),

                'total_failed_products' => new Zend_Db_Expr("
            (SELECT COUNT(*)
             FROM ixpf_purchase_order_items items
             WHERE items.ixpf_purchase_id = main_table.ixpf_purchase_id
             AND items.process_status = 'failed')
        ")
            ])
            ->group('main_table.ixpf_purchase_id')
            ->order('main_table.entity_id DESC');

        return $this;
    }
}