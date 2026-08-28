<?php

/**
 * Copyright © Carpet Avenue GmbH. All rights reserved.
 *
 * @package CarpetAvenue_B2B
 * @author  Strativ Magento Team
 */

declare(strict_types=1);

namespace CarpetAvenue\B2B\Model;

use Magento\Framework\Model\AbstractModel;

class ResellerApplication extends AbstractModel
{
    protected function _construct(): void
    {
        $this->_init(\CarpetAvenue\B2B\Model\ResourceModel\ResellerApplication::class);
    }
}
