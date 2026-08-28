<?php

/**
 * Copyright © Carpet Avenue GmbH. All rights reserved.
 *
 *
 * This source file is subject to the Carpet Avenue GmbH license
 *
 * @category  Product
 * @package   CarpetAvenue_Productify
 * @copyright Copyright (c) carpetavenue.com (https://carpetavenue.com)
 * @author    Strativ Magento Team
 */

declare(strict_types=1);

namespace CarpetAvenue\Productify\Model\ResourceModel;

use Magento\Framework\Model\AbstractModel;
use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

class GroupProductCache extends AbstractDb
{
    protected function _construct(): void
    {
        $this->_init('carpetavenue_productify_group_product_cache', 'design_id_group');
    }

    public function getIdFieldName(): null
    {
        return null; // indicates composite key
    }

    public function load(AbstractModel $object, $value, $field = null): GroupProductCache|static
    {
        if (is_array($value)) {
            $select = $this->getConnection()->select()
                ->from($this->getMainTable())
                ->where('design_id_group = ?', $value[0])
                ->where('store_id = ?', $value[1]);
            $data = $this->getConnection()->fetchRow($select);
            if ($data) {
                $object->setData($data);
            }
            return $this;
        }

        return parent::load($object, $value, $field);
    }
}
