<?php

declare(strict_types=1);

namespace Evalent\CheckoutModsJP\Logger\Handler;

use Magento\Framework\Filesystem\DriverInterface;
use Magento\Framework\Logger\Handler\Base;
use Monolog\Logger;

/**
 * Writes daily logs to var/log/carpetavenue/checkout/sanitize-YYYY-MM-DD.log
 *
 * File name carries the current date so a new file is created each day.
 */
class SanitizeAddress extends Base
{
    protected $loggerType = Logger::DEBUG;

    protected $fileName = 'var/log/carpetavenue/checkout/sanitize.log';

    public function __construct(
        DriverInterface $filesystem,
        ?string $filePath = null,
        ?string $fileName = null
    ) {
        $this->fileName = sprintf(
            'var/log/carpetavenue/checkout/sanitize-%s.log',
            date('Y-m-d')
        );

        parent::__construct($filesystem, $filePath, $fileName);
    }
}
