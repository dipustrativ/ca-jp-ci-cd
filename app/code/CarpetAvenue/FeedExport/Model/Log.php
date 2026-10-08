<?php

/**
 * Copyright © Carpet Avenue GmbH. All rights reserved.
 *
 * @category  Carpet Avenue
 * @package   CarpetAvenue_FeedExport
 * @copyright Copyright (c) carpetavenue.com (https://carpetavenue.com)
 * @author    Strativ Magento Team
 */

declare(strict_types=1);

namespace CarpetAvenue\FeedExport\Model;

use CarpetAvenue\FeedExport\Model\ResourceModel\Log as LogResource;
use Magento\Framework\Model\AbstractModel;

/**
 * One row per export run: what triggered it, how many records, which files, how long.
 */
class Log extends AbstractModel
{
    public const STATUS_RUNNING = 0;
    public const STATUS_SUCCESS = 1;
    public const STATUS_FAILED = 2;

    protected function _construct(): void
    {
        $this->_init(LogResource::class);
    }
}
