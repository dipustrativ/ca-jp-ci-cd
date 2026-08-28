<?php

namespace Evalent\InvoicePaymentMods\Model\ResourceModel\Order\Invoice\Grid;

use Magento\Framework\Data\Collection\Db\FetchStrategyInterface as FetchStrategy;
use Magento\Framework\Data\Collection\EntityFactoryInterface as EntityFactory;
use Magento\Framework\Event\ManagerInterface as EventManager;
use Psr\Log\LoggerInterface as Logger;

class Collection extends \Magento\Sales\Model\ResourceModel\Order\Invoice\Orders\Grid\Collection
{
    /**
     * Initialize dependencies.
     *
     * @param EntityFactory $entityFactory
     * @param Logger $logger
     * @param FetchStrategy $fetchStrategy
     * @param EventManager $eventManager
     * @param string $mainTable
     * @param string $resourceModel
     */
    public function __construct(
        EntityFactory $entityFactory,
        Logger $logger,
        FetchStrategy $fetchStrategy,
        EventManager $eventManager,
        $mainTable = 'sales_invoice_grid',
        $resourceModel = \Magento\Sales\Model\ResourceModel\Order\Invoice::class
    ) {
        parent::__construct($entityFactory, $logger, $fetchStrategy, $eventManager, $mainTable, $resourceModel);
    }


    /**
     * Add payment additional information column to grid
     *
     * @return $this|Collection|void
     */
    protected function _initSelect()
    {
        parent::_initSelect();

        // Map `entity_id` column to prevent ambiguous column name (from main table and payment_table)
        $this->addFilterToMap('entity_id', 'main_table.entity_id');

        $this->getSelect()->joinLeft(
            ['payment_table' => $this->getTable('sales_order_payment')],
            'main_table.order_id = payment_table.parent_id',
            [
                'payment_table_additional_information' => 'payment_table.additional_information'
            ]
        );

        return $this;
    }

    /**
     * @inheritDoc
     */
    protected function fixWhereAdditionalInformation($select){
        $where = $select->getPart(\Magento\Framework\DB\Select::WHERE);
        if (is_array($where) || count($where)) {
            foreach($where as $key => $value){
                $where[$key] = str_replace('`payment_table_additional_information`','`payment_table`.`additional_information`',$value);
            }
            $select->setPart(\Magento\Framework\DB\Select::WHERE,$where);
        }
    }

    public function getSelect(){
        if ($select = parent::getSelect()){
            $this->fixWhereAdditionalInformation($select);
        }
        return $select;
    }

    

    /**
     * @inheritDoc
     */
    public function getSelectCountSql(){
        if($countSelect = parent::getSelectCountSql()){
            $this->fixWhereAdditionalInformation($countSelect);
        }
        return $countSelect;
    }


    /**
     * Extract payment brand code from all payment additional info
     *
     * @return $this|Collection
     */
    protected function _afterLoad()
    {
        parent::_afterLoad();

        foreach ($this->getItems() as $item) {
            if (isset($item['payment_table_additional_information'])) {
                $info = json_decode($item['payment_table_additional_information']);
                if (isset($info->brand_code)) {
                    $item['payment_table_additional_information'] = $info->brand_code;
                } else {
                    $item['payment_table_additional_information'] = null;
                }
            }
        }

        return $this;
    }
}
