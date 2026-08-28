<?php

/**
 * Copyright © Carpet Avenue GmbH. All rights reserved.
 *
 * @category  Order
 * @package   CarpetAvenue_Orderify
 * @copyright Copyright (c) carpetavenue.com (https://carpetavenue.com)
 * @author    Strativ Magento Team
 */

declare(strict_types=1);

namespace CarpetAvenue\Orderify\Logger\Handler;

use Magento\Framework\Filesystem\DriverInterface;
use Magento\Framework\Logger\Handler\Base;
use Monolog\Logger;

/**
 * Writes logs to var/log/carpetavenue/orderify/ with daily rotation.
 *
 * File pattern: orderify_YYYY-MM-DD.log
 */
class DateRotating extends Base
{
    protected $loggerType = Logger::DEBUG;

    public function __construct(
        DriverInterface $filesystem,
        ?string $filePath = null,
        ?string $fileName = null
    ) {
        $fileName = '/var/log/carpetavenue/orderify/orderify_inventory_' . date('Y-m-d') . '.log';

        parent::__construct($filesystem, $filePath, $fileName);
    }
}
