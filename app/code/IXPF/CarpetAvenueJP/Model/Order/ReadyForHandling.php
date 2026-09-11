<?php
declare(strict_types=1);

namespace IXPF\CarpetAvenueJP\Model\Order;

use Magento\Framework\App\ResourceConnection;

/**
 * Marks orders as "ready for handling".
 *
 * CarpetAvenue-specific business action — lives in IXPF_CarpetAvenue, not in the
 * generic IXPF_Syncer module. Used by the syncer bulk-action plugin
 * {@see \IXPF\CarpetAvenueJP\Plugin\Syncer\Orders\BulkActionReadyForHandling}.
 *
 * Rules: order must have an invoice and must not already be
 * ready_for_handling / canceled / closed.
 */
class ReadyForHandling
{
    public const STATUS = 'ready_for_handling';
    public const STATE  = 'processing';

    public function __construct(
        protected readonly ResourceConnection $resourceConnection
    ) {
    }

    /**
     * Mark each order as ready for handling. Per-order failures are captured,
     * never thrown — one bad order does not stop the rest.
     *
     * @param int[]       $orderIds
     * @param string|null $by  Attribution shown in the status-history comment
     * @return array  per-order results:
     *                [['order_id'=>int, 'increment_id'=>?string, 'success'=>bool,
     *                  'status'=>?string, 'state'=>?string, 'message'|'error'=>string], ...]
     */
    public function execute(array $orderIds, ?string $by = null): array
    {
        $orderIds = array_values(array_unique(array_filter(array_map('intval', $orderIds))));
        if (!$orderIds) {
            return [];
        }

        $connection   = $this->resourceConnection->getConnection();
        $orderTable   = $this->resourceConnection->getTableName('sales_order');
        $gridTable    = $this->resourceConnection->getTableName('sales_order_grid');
        $historyTable = $this->resourceConnection->getTableName('sales_order_status_history');

        $select = $connection->select()
            ->from(['main_table' => $orderTable], ['entity_id', 'increment_id', 'status', 'state'])
            ->joinLeft(
                ['si' => $this->resourceConnection->getTableName('sales_invoice')],
                'main_table.entity_id = si.order_id',
                ['invoice_id' => 'si.entity_id']
            )
            ->where('main_table.entity_id IN (?)', $orderIds)
            ->group('main_table.entity_id');

        $orders  = $connection->fetchAll($select);
        $found   = [];
        $results = [];
        $comment = '| ' . ($by ?? 'IXPF API') . ' <br> bulk action: mark as ready for handling <br>';

        foreach ($orders as $order) {
            $id  = (int) $order['entity_id'];
            $inc = $order['increment_id'];
            $found[$id] = true;

            if (!$order['invoice_id']) {
                $results[] = $this->fail($id, $inc, 'has no invoice, cannot be marked as ready for handling');
                continue;
            }
            if ($order['status'] === self::STATUS) {
                $results[] = $this->fail($id, $inc, 'is already marked as ready for handling');
                continue;
            }
            if ($order['status'] === 'canceled') {
                $results[] = $this->fail($id, $inc, 'is canceled, cannot be marked as ready for handling');
                continue;
            }
            if ($order['status'] === 'closed') {
                $results[] = $this->fail($id, $inc, 'is closed, cannot be marked as ready for handling');
                continue;
            }

            $connection->update(
                $orderTable,
                ['status' => self::STATUS, 'state' => self::STATE],
                ['entity_id = ?' => $id]
            );
            $connection->update(
                $gridTable,
                ['status' => self::STATUS],
                ['entity_id = ?' => $id]
            );
            $connection->insert(
                $historyTable,
                [
                    'parent_id'            => $id,
                    'is_customer_notified' => 0,
                    'is_visible_on_front'  => 0,
                    'comment'              => $comment,
                    'status'               => self::STATUS,
                    'entity_name'          => 'order',
                ]
            );

            $results[] = [
                'order_id'     => $id,
                'increment_id' => $inc,
                'success'      => true,
                'status'       => self::STATUS,
                'state'        => self::STATE,
                'message'      => "Order #$inc marked as ready for handling",
            ];
        }

        foreach ($orderIds as $id) {
            if (empty($found[$id])) {
                $results[] = [
                    'order_id'     => $id,
                    'increment_id' => null,
                    'success'      => false,
                    'error'        => "Order #$id not found",
                ];
            }
        }

        return $results;
    }

    private function fail(int $id, ?string $inc, string $reason): array
    {
        return [
            'order_id'     => $id,
            'increment_id' => $inc,
            'success'      => false,
            'error'        => "Order #$inc $reason",
        ];
    }
}
