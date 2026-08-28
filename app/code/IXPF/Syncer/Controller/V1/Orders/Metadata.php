<?php

namespace IXPF\Syncer\Controller\V1\Orders;

use Exception;
use IXPF\Syncer\Controller\AbstractController;
use IXPF\Syncer\Helper\TokenValidator;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Controller\Result\JsonFactory;

class Metadata extends AbstractController
{
    public function __construct(
        Context $context,
        TokenValidator $tokenValidator,
        JsonFactory $resultFactory,
        protected ResourceConnection $resourceConnection
    )
    {
        parent::__construct($context, $tokenValidator, $resultFactory);
    }

    public function execute()
    {
        try {
            $connection = $this->resourceConnection->getConnection();

            //-- Get Order Statuses
            $orderStatus = $this->resourceConnection->getTableName('sales_order_status');
            $salesOrderStatus = $connection->select()->from($orderStatus);
            $statuses = $connection->fetchAll($salesOrderStatus);

            //-- Get Order States with their Statuses
            $orderState = $this->resourceConnection->getTableName('sales_order_status_state');
            $salesOrderState = $connection->select()
                ->from(['state_table' => $orderState], ['state', 'status', 'is_default'])
                ->joinLeft(
                    ['status_table' => $orderStatus],
                    'state_table.status = status_table.status',
                    ['status_label' => 'label']
                )
                ->order('state_table.state');
            $stateStatuses = $connection->fetchAll($salesOrderState);
            $sateData = [];
            foreach ($stateStatuses as $row) {
                if (!isset($row['state'])) {
                    continue;
                }
                $stateCode = $row['state'];
                if (!isset($sateData[$stateCode])) {
                    $stateTitle = str_replace('_', ' ', $stateCode);
                    $sateData[$stateCode] = [
                        'state' => $stateCode,
                        'title' => ucwords($stateTitle),
                        'statuses' => []
                    ];
                }
                if (isset($row['status'])) {
                    $sateData[$stateCode]['statuses'][] = [
                        'status' => $row['status'],
                        'title' => $row['status_label'] ?? ucwords(str_replace('_', ' ', $row['status'])),
                        'is_default' => (bool)($row['is_default'] ?? false)
                    ];
                }
            }
            $sateData = array_values($sateData);

            //-- Get Customer Groups
            $customerGroupTable = $this->resourceConnection->getTableName('customer_group');
            $customerGroupSelect = $connection->select()->from($customerGroupTable, ['customer_group_id', 'customer_group_code']);
            $customerGroups = $connection->fetchAll($customerGroupSelect);

            return $this->jsonResponse([
                'success' => true,
                'data' => [
                    'statuses' => $statuses,
                    'states' => $sateData,
                    'customer_groups' => $customerGroups
                ]
            ]);
        } catch (Exception $e) {
            return $this->jsonResponse([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }
}