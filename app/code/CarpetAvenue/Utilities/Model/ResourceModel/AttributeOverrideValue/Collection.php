<?php
/**
 * Copyright © Carpet Avenue GmbH. All rights reserved.
 *
 * @package   CarpetAvenue_Utilities
 * @author    Strativ Magento Team
 */

declare(strict_types=1);

namespace CarpetAvenue\Utilities\Model\ResourceModel\AttributeOverrideValue;

use CarpetAvenue\Utilities\Model\AttributeOverrideValue as Model;
use CarpetAvenue\Utilities\Model\ResourceModel\AttributeOverrideValue as ResourceModel;
use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;

class Collection extends AbstractCollection
{
    protected function _construct(): void
    {
        $this->_init(Model::class, ResourceModel::class);
    }
}
