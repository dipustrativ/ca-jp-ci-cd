<?php

declare(strict_types=1);

namespace Evalent\OrderMods\Overwrite\Model\ResourceModel\Order\Invoice\Grid;

class Collection extends \Magento\Framework\View\Element\UiComponent\DataProvider\SearchResult
{

    /**
     * Add payment additional information column to grid
     *
     * @return Collection
     */
    protected function _initSelect()
    {
        parent::_initSelect();


        $this->addFilterToMap('entity_id', 'main_table.entity_id');

        $this->getSelect()->joinLeft(
            ['payment_table' => $this->getTable('sales_order_payment')],
            'main_table.order_id = payment_table.parent_id',
            [
                'payment_table_additional_information' => 'payment_table.additional_information'
            ],
        );

        return $this;
    }

    protected function fixWhereAdditionalInformation($select){
        $where = $select->getPart(\Magento\Framework\DB\Select::WHERE);
        if (is_array($where) || count($where)) {
            foreach($where as $key => $value){
                $where[$key] = str_replace('`payment_table_additional_information`','`payment_table`.`additional_information`',$value);
            }
            $select->setPart(\Magento\Framework\DB\Select::WHERE,$where);
        }
    }
    
    /**
     * @inheritDoc
     */
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
     * @return Collection
     */
    protected function _afterLoad()
    {
        parent::_afterLoad();

        foreach ($this->getItems() as $item) {
            if (isset($item['payment_table_additional_information']) && $item['payment_table_additional_information'] !== null) {

                $info = json_decode($item['payment_table_additional_information']);
                if (isset($info->brand_code)) {
                    $item['payment_table_additional_information'] = $info->brand_code;
                } else {
                    $item['payment_table_additional_information'] = "";
                }
            }
        }

        return $this;
    }
}
