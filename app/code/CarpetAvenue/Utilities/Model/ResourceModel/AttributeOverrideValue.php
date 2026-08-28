<?php
/**
 * Copyright © Carpet Avenue GmbH. All rights reserved.
 *
 * @package   CarpetAvenue_Utilities
 * @author    Strativ Magento Team
 */

declare(strict_types=1);

namespace CarpetAvenue\Utilities\Model\ResourceModel;

use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

class AttributeOverrideValue extends AbstractDb
{
    protected function _construct(): void
    {
        $this->_init('carpetavenue_attribute_override_value', 'id');
    }
}
