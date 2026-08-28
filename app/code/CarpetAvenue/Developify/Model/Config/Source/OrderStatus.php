<?php
declare(strict_types=1);

namespace CarpetAvenue\Developify\Model\Config\Source;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Data\OptionSourceInterface;
use Magento\Sales\Model\Order;

class OrderStatus implements OptionSourceInterface
{
    public function __construct(
        protected ResourceConnection $resourceConnection
    )
    {
    }

    public function toOptionArray(): array
    {
        $connection = $this->resourceConnection->getConnection();
        $tableName = $this->resourceConnection->getTableName('sales_order_status');
        $select = $connection->select()
            ->from($tableName, ['status', 'label'])
            ->order('label ASC');
        $statuses = $connection->fetchAll($select);
        $options = [
            ['value' => '', 'label' => __('-- Please Select --')]
        ];
        foreach ($statuses as $status) {
            $options[] = ['value' => $status['status'], 'label' => __($status['label'])];
        }
        return $options;
    }
}