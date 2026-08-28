<?php

namespace IXPF\Syncer\Model\Indexer;

use IXPF\Syncer\Helper\Webhook;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Indexer\ActionInterface;

class Order implements ActionInterface, \Magento\Framework\Mview\ActionInterface
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
        $this->webhookHelper->run("product_order", "indexer");
    }

    /**
     * @param array $ids
     * @return void
     */
    public function executeList(array $ids)
    {
        $this->execute($ids);
    }

    /**
     * @param $id
     * @return void
     */
    public function executeRow($id)
    {
        $this->execute([$id]);
    }

    public function execute($ids)
    {
        $ids = array_unique($ids);
        if (count($ids)) {
            $connection = $this->resourceConnection->getConnection();
            $salesOrderTable = $connection->getTableName('sales_order');
            $salesOrderItemTable = $connection->getTableName('sales_order_item');
            $select = $connection->select()
                ->from(['soi' => $salesOrderItemTable], ['product_id'])
                ->join(
                    ['so' => $salesOrderTable],
                    'soi.order_id = so.entity_id',
                    []
                )
                ->where('so.entity_id IN (?)', $ids);
            $productIds = $connection->fetchCol($select);
            $productIds = array_unique($productIds);
            $this->webhookHelper->updateOrCreateJob("product_order", $productIds);

            //Todo: upcoming realtime order processing will handle here
            //-- code...
        }
    }
}
