<?php

namespace IXPF\Syncer\Model\Indexer;

use IXPF\Syncer\Helper\Webhook;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Indexer\ActionInterface;

class Customer implements ActionInterface, \Magento\Framework\Mview\ActionInterface
{
    private Webhook $webhookHelper;
    private ResourceConnection $resourceConnection;

    public function __construct(
        Webhook $webhook,
        ResourceConnection $resourceConnection
    )
    {
        $this->webhookHelper = $webhook;
        $this->resourceConnection = $resourceConnection;
    }

    /**
     * @return void
     */
    public function executeFull()
    {
        $this->webhookHelper->run("customer", "indexer");
    }

    /**
     * @param array $ids
     * @return void
     */
    public function executeList(array $ids)
    {
        $this->queue($ids, false);
    }

    /**
     * @param $id
     * @return void
     */
    public function executeRow($id)
    {
        $this->queue([$id], false);
    }

    /**
     * Mview (cron) entry point: a failed upsert throws so the ids are retried.
     */
    public function execute($ids)
    {
        $this->queue($ids, true);
    }

    private function queue(array $ids, bool $throwOnFailure): void
    {
        $ids = array_unique($ids);
        if (count($ids)) {
            $customerIds = array_values(array_unique(array_map('intval', $ids)));
            $this->webhookHelper->updateOrCreateJob("customer", $customerIds, $throwOnFailure);
        }
    }
}
