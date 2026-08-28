<?php

/**
 * Copyright © Carpet Avenue GmbH. All rights reserved.
 *
 *
 * This source file is subject to the Carpet Avenue GmbH license
 *
 * @category  Product
 * @package   CarpetAvenue_Productify
 * @copyright Copyright (c) carpetavenue.com (https://carpetavenue.com)
 * @author    Strativ Magento Team
 */

declare(strict_types=1);

namespace CarpetAvenue\Productify\Cron;

use CarpetAvenue\Productify\Helper\Config;
use CarpetAvenue\Productify\Helper\Data;
use CarpetAvenue\Productify\Helper\Logger;
use CarpetAvenue\Productify\Service\Core\QueueManagement;
use CarpetAvenue\Productify\Service\ProductSync;
use Exception;
use Magento\Framework\Indexer\IndexerRegistry;

class ProcessQueue
{
    public function __construct(
        protected IndexerRegistry $indexerRegistry,
        protected Config $configHelper,
        protected Logger $logger,
        protected QueueManagement $queueManagement,
        protected ProductSync $productSync
    ) {
    }

    public function execute(): void
    {
        if (!$this->configHelper->isCronJobEnabled()) {
            $this->logger->info('Productify : [Queue] Product cron job is disabled. Skipping execution.');
            return;
        }

        try {
            $this->logger->info('Productify : [Queue] Cron processing queue updates for products');
            $batch = $this->queueManagement->getNextProductBatch(Data::QUEUE_BATCH_SIZE);

            if (empty($batch)) {
                $this->logger->info('Productify : [Queue] No products in queue to process.');
                return;
            }

            $this->logger->info('Productify : [Queue] Processing batch of ' . count($batch) . ' products from queue.');
            $productIds = array_column($batch, 'product_id');
            $this->logger->info('Productify : [Queue] Product IDs in batch: ' . implode(', ', $productIds));
            $this->productSync->buildAllStores($productIds);

            $this->logger->info('Productify : [Queue] Marking batch as complete.');
            $this->queueManagement->markBatchComplete($productIds);

        } catch (Exception $exception) {
            $this->logger->error('Productify : [Queue] Product cron failed'.$exception->getMessage());
        }
    }
}