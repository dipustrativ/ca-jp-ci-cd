<?php
/**
 * Copyright © Carpet Avenue GmbH. All rights reserved.
 *
 * @package CarpetAvenue_Utilities
 */

declare(strict_types=1);

namespace CarpetAvenue\Utilities\Model\ResourceModel\ImageResizeLog;

use CarpetAvenue\Utilities\Model\ImageResizeLog;
use CarpetAvenue\Utilities\Model\ResourceModel\ImageResizeLog as ImageResizeLogResource;
use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;

class Collection extends AbstractCollection
{
    protected function _construct(): void
    {
        $this->_init(ImageResizeLog::class, ImageResizeLogResource::class);
    }
}

