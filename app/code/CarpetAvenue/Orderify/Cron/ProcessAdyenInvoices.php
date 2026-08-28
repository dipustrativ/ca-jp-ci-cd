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

namespace CarpetAvenue\Orderify\Cron;

use CarpetAvenue\Orderify\Helper\Config;
use CarpetAvenue\Orderify\Service\AdyenInvoice\Processor;
use Exception;
use Psr\Log\LoggerInterface;

/**
 * Cron entry point for automatic Adyen invoice processing.
 * Thin wrapper — all business logic lives in the AdyenInvoice services.
 */
class ProcessAdyenInvoices
{
    public function __construct(
        private readonly Config $configHelper,
        private readonly Processor $processor,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(): void
    {
        if (!$this->configHelper->isAdyenAutoInvoiceEnabled()) {
            return;
        }

        try {
            $this->logger->info('AdyenAutoInvoice: cron run started.');
            $this->processor->process();
        } catch (Exception $exception) {
            $this->logger->error(
                'AdyenAutoInvoice: cron run failed: ' . $exception->getMessage(),
                ['exception' => $exception]
            );
        }
    }
}
