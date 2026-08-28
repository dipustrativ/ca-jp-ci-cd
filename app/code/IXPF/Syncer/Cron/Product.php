<?php

namespace IXPF\Syncer\Cron;

use IXPF\Syncer\Helper\Config;
use Magento\Framework\Indexer\IndexerRegistry;
use Psr\Log\LoggerInterface;

class Product
{
    const INDEXER_ID = 'ixpf_changeable_product';

    public function __construct(
        protected IndexerRegistry $indexerRegistry,
        protected Config $configHelper,
        protected LoggerInterface $logger
    ) {
    }

    public function execute(): void
    {
        if (!$this->configHelper->isCronJobEnabled()) {
            $this->logger->info('IXPF: Product cron job is disabled. Skipping execution.');
            return;
        }

        try {
            $this->logger->info('IXPF: Cron processing mview updates for products');

            $indexer = $this->indexerRegistry->get(self::INDEXER_ID);

            if ($indexer->isScheduled()) {
                $indexer->getView()->update();
                $this->logger->info('IXPF: Cron completed product processing');
            } else {
                $this->logger->info('IXPF: Indexer is not in scheduled mode');
            }

        } catch (\Exception $e) {
            $this->logger->error('IXPF: Product cron failed', ['error' => $e->getMessage()]);
        }
    }
}