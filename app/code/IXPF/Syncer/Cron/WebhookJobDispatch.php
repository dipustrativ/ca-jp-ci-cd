<?php

namespace IXPF\Syncer\Cron;

use IXPF\Syncer\Helper\Config;
use IXPF\Syncer\Helper\Logger\WebhookLogger;
use IXPF\Syncer\Helper\Webhook;

class WebhookJobDispatch
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

        $dispatchLimit = $this->configHelper->getWebhookDispatchLimit();
        $parallel      = $this->configHelper->isWebhookParallelProcessingEnabled();
        $workers       = $this->configHelper->getWebhookParallelWorkers();

        try {
            //-- pending product webhook trigger
            $this->webhook->runBatch('product', 'pending', $dispatchLimit, $parallel, $workers);

            //-- pending product_order webhook trigger
            $this->webhook->runBatch('product_order', 'pending', $dispatchLimit, $parallel, $workers);

            //-- pending inventory webhook trigger
            $this->webhook->runBatch('inventory', 'pending', $dispatchLimit, $parallel, $workers);

            //-- pending customer webhook trigger
            $this->webhook->runBatch('customer', 'pending', $dispatchLimit, $parallel, $workers);
        } catch (\Exception $e) {
            $this->logger->writeDaily("IXPF: WebhookJobDispatch cron failed: " . $e->getMessage());
        }

        return;
    }
}
