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

namespace CarpetAvenue\Productify\Model\Indexer;

use CarpetAvenue\Productify\Helper\Config;
use CarpetAvenue\Productify\Helper\Logger;
use CarpetAvenue\Productify\Service\Core\QueueManagement;
use CarpetAvenue\Productify\Service\ProductSync;
use CarpetAvenue\Productify\Service\VariantBuilder;
use Magento\Framework\Indexer\ActionInterface;
use Magento\Framework\Mview\ActionInterface as MviewActionInterface;

class Product implements ActionInterface, MviewActionInterface
{
    public function __construct(
        protected VariantBuilder $variantBuilder,
        protected Config $configHelper,
        protected ProductSync $productSync,
        protected QueueManagement $queue,
        protected Logger $logger
    ) {
    }

    public function execute($ids): void
    {
        /*
         * Group Product Indexer Functionality
         * */
        if (!$this->configHelper->isAutoSyncEnabled()) {
            $this->logger->info('Productify: [CRON] Auto sync is disabled. Skipping variant cache rebuild.');
            return;
        }
        if (!empty($ids)) {
            $this->logger->info('Productify: [CRON] Adding product IDs to queue: ' . implode(', ', (array)$ids));
            $ids = array_unique(array_filter((array)$ids));
            $this->queue->addProductsByIdToQueue($ids);
        }
    }

    public function executeFull(): void
    {
        if (!$this->configHelper->isAutoSyncEnabled()) {
            $this->logger->info('Productify: [CRON] Auto sync is disabled.');
            return;
        }

        if (!$this->configHelper->isFullReindexEnabled()) {
            $this->logger->info('Productify: [CRON] Productify full reindex sync is disabled.');
            return;
        }

        $this->logger->info('Productify: [CRON] Adding all data to queue from full reindex.');
        $this->queue->addAllProductsToQueue();
    }

    public function executeList(array $ids): void
    {
        $this->execute($ids);
    }

    public function executeRow($id): void
    {
        $this->execute([$id]);
    }
}