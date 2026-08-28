<?php

namespace IXPF\Syncer\Cron;

use IXPF\Syncer\Helper\Config;
use IXPF\Syncer\Helper\Logger\WebhookLogger;
use IXPF\Syncer\Helper\Webhook;

class WebhookFailedJobDispatch

{

    public function __construct(
        private readonly Webhook $webhook,
        protected Config $configHelper,
        protected WebhookLogger $logger
    )
    {
    }

    /**
     * @return void
     */
    public function execute(): void
    {
        if (!$this->configHelper->isCronJobEnabled()) {
            $this->logger->writeDaily('IXPF: Product cron job is disabled. Skipping execution.');
            return;
        }

        try {
            $this->logger->writeDaily('IXPF: Starting failed webhook dispatch cron job.');
            //-- failed product webhook trigger
            $this->webhook->runBatch('product', 'failed', 30);

            //-- failed product_order webhook trigger
            $this->webhook->runBatch('product_order', 'failed', 25);

            //-- failed inventory webhook trigger
            $this->webhook->runBatch('inventory', 'failed', 25);

            //-- failed customer webhook trigger
            $this->webhook->runBatch('customer', 'failed', 25);

        } catch (\Exception $e) {
            $this->logger->writeDaily("IXPF: WebhookFailedJobDispatch cron failed: " . $e->getMessage());
        }
    }
}
