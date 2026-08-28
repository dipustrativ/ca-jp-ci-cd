<?php

declare(strict_types=1);

namespace IXPF\Syncer\Cron;

use IXPF\Syncer\Helper\Config;
use IXPF\Syncer\Helper\Logger\BulkUpdateRevertLogger;
use IXPF\Syncer\Services\ProductBulkUpdate\ProductUpdate;
use IXPF\Syncer\Services\ProductBulkUpdate\WebhookRetry;

class BulkRevert
{

    public function __construct(
        protected ProductUpdate $productUpdate,
        protected WebhookRetry $webhookRetry,
        protected readonly BulkUpdateRevertLogger $logger,
        protected Config $configHelper
    )
    {
    }

    public function execute(): void
    {
        if (!$this->configHelper->isCronJobEnabled()) {
             $this->logger->writeDaily('IXPF: Bulk Revert cron job is disabled. Skipping execution.', "warn");
            return;
        }

        try {
            $this->productUpdate->process();
        } catch (\Exception $e) {
            $this->logger->writeDaily("Bulk Revert Cron Job Failed: " . $e->getMessage(), "error");
        }

        //-- retry any failed webhooks after processing the main queue, to ensure we don't have any stuck jobs in the system
        try {
            $this->webhookRetry->process();
        } catch (\Exception $e) {
            $this->logger->writeDaily("Webhook Retry in Bulk Revert Cron Job Failed: " . $e->getMessage(), "error");
        }
    }
}