<?php
/**
 * Copyright © Carpet Avenue GmbH. All rights reserved.
 *
 * @package   CarpetAvenue_Utilities
 * @author    Strativ Magento Team
 */

declare(strict_types=1);

namespace CarpetAvenue\Utilities\Model;

use Magento\Framework\Model\AbstractModel;

class AttributeOverrideValue extends AbstractModel
{
    protected function _construct(): void
    {
        $this->_init(ResourceModel\AttributeOverrideValue::class);
    }
}