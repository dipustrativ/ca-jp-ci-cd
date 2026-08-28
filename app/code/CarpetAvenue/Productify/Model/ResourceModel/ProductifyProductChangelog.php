<?php

namespace CarpetAvenue\Productify\Model\ResourceModel;

use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

class ProductifyProductChangelog extends AbstractDb
{
    protected function _construct(): void
    {
        $this->_init('carpetavenue_productify_product_change_log', 'entity_id');
    }

    public function addNewLog(array $data)
    {
        try {
            $connection = $this->getConnection();
            $table = $this->getMainTable();
            $connection->insert($table, $data);
        } catch (\Exception $exception) {

        }
    }

}