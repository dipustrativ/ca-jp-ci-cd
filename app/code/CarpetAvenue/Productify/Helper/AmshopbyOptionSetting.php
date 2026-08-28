<?php

namespace CarpetAvenue\Productify\Helper;

use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Framework\App\Helper\Context;
use Magento\Framework\App\ResourceConnection;

class AmshopbyOptionSetting extends AbstractHelper
{
    public function __construct(
        Context $context,
        protected ResourceConnection $resourceConnection
    )
    {
        parent::__construct($context);
    }

    /**
     * @param $attributeCode
     * @return array
     */
    public function getOptionSettings($attributeCode): array
    {
        $connection = $this->resourceConnection->getConnection();
        $tableName = $this->resourceConnection->getTableName('amasty_amshopby_option_setting');
        $select = $connection->select()
            ->from(['e' => $tableName])
            ->where('e.attribute_code = ?', $attributeCode);
        $result = $connection->fetchAll($select);

        $data = [];
        foreach ($result as $row) {
            $data[$row['value']] = $row;
        }
        return $data;
    }

}