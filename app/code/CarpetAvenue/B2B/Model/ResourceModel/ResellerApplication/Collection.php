<?php

/**
 * Copyright © Carpet Avenue GmbH. All rights reserved.
 *
 * @package CarpetAvenue_B2B
 * @author  Strativ Magento Team
 */

declare(strict_types=1);

namespace CarpetAvenue\B2B\Model\ResourceModel\ResellerApplication;

use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;

class Collection extends AbstractCollection
{
    protected $_idFieldName = 'application_id';

    protected function _construct(): void
    {
        $this->_init(
            \CarpetAvenue\B2B\Model\ResellerApplication::class,
            \CarpetAvenue\B2B\Model\ResourceModel\ResellerApplication::class
        );
    }
}
