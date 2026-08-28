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
use Magento\Framework\Indexer\IndexerRegistry;
use Psr\Log\LoggerInterface;

class ProcessView
{
    protected IndexerRegistry $indexerRegistry;
    protected Config $configHelper;
    protected LoggerInterface $logger;

    private const INDEXER_ID = 'productify_indexer_product';

    public function __construct(
        IndexerRegistry $indexerRegistry,
        Config $configHelper,
        LoggerInterface $logger
    ) {
        $this->indexerRegistry = $indexerRegistry;
        $this->configHelper = $configHelper;
        $this->logger = $logger;
    }

    public function execute(): void
    {
        if (!$this->configHelper->isCronJobEnabled()) {
            $this->logger->info('Productify: Product cron job is disabled. Skipping execution.');
            return;
        }

        try {
            $this->logger->info('Productify: Cron processing mview updates for products');

            $indexer = $this->indexerRegistry->get(self::INDEXER_ID);

            if ($indexer->isScheduled()) {
                $indexer->getView()->update();
                $this->logger->info('Productify: Cron completed product processing');
            } else {
                $this->logger->info('Productify: Indexer is not in scheduled mode');
            }

        } catch (\Exception $e) {
            $this->logger->error('Productify: Product cron failed', ['error' => $e->getMessage()]);
        }
    }
}