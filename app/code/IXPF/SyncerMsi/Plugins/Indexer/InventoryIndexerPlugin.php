<?php

declare(strict_types=1);

namespace IXPF\SyncerMsi\Plugins\Indexer;

use Exception;
use IXPF\Syncer\Helper\Logger\WebhookLogger;
use IXPF\SyncerMsi\Helpers\MsiWebhookHelper;

class InventoryIndexerPlugin
{
    public function __construct(
        protected MsiWebhookHelper $msiWebhook,
        protected WebhookLogger $webhookLogger
    )
    {
    }

    /**
     * @param $subject
     * @param $proceed
     * @param $sourceItemIds
     * @return mixed
     */
    public function aroundExecuteList($subject, $proceed, $sourceItemIds): mixed
    {
        try {
            if (is_array($sourceItemIds) && count($sourceItemIds)) {
                $this->msiWebhook->msiProcess($sourceItemIds);
            }
        } catch (Exception $e) {
            $this->webhookLogger->writeDaily('MSI Inventory Indexer Plugin Error: ' . $e->getMessage(), "error");
        }

        return $proceed($sourceItemIds);
    }
}