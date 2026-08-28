<?php

declare(strict_types=1);

namespace IXPF\SyncerMsi\Helpers;

use Exception;
use IXPF\Syncer\Helper\Logger\WebhookLogger;
use IXPF\Syncer\Helper\Webhook;
use Magento\Framework\App\ResourceConnection;

class MsiWebhookHelper
{
    public function __construct(
        protected Webhook $webhook,
        protected WebhookLogger $webhookLogger,
        protected ResourceConnection $resourceConnection
    )
    {
    }

    /**
     * @param array $sourceItemIds
     * @return void
     */
    public function msiProcess(array $sourceItemIds = []): void
    {
        try {
            $connection = $this->resourceConnection->getConnection();
            $tableName = $this->resourceConnection->getTableName('inventory_source_item');

            $select = $connection->select()
                ->from(['isi' => $tableName], ['sku'])
                ->where('isi.source_item_id IN (?)', $sourceItemIds);
            $skus = $connection->fetchCol($select);
            $skus = array_unique($skus);
            if (empty($skus)) {
                return;
            }

            //-- get product ids from skus
            $productTable = $this->resourceConnection->getTableName('catalog_product_entity');
            $selectProducts = $connection->select()
                ->from(['cpe' => $productTable], ['entity_id'])
                ->where('cpe.sku IN (?)', $skus);
            $productIds = $connection->fetchCol($selectProducts);

            //-- trigger webhook for these product ids
            if (!empty($productIds)) {
                $this->webhook->updateOrCreateJob('inventory', $productIds);
            }

            return;
        } catch (Exception $e) {
            $this->webhookLogger->writeDaily('MSI Webhook Process Error: ' . $e->getMessage(), "error");
            return;
        }
    }

}