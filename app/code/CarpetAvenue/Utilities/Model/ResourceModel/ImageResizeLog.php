<?php
/**
 * Copyright © Carpet Avenue GmbH. All rights reserved.
 *
 * @package CarpetAvenue_Utilities
 */

declare(strict_types=1);

namespace CarpetAvenue\Utilities\Model\ResourceModel;

use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

class ImageResizeLog extends AbstractDb
{
    protected function _construct(): void
    {
        $this->_init('carpetavenue_image_resize_log', 'id');
    }
}

