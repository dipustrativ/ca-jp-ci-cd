<?php

namespace CarpetAvenue\Developify\Controller\Adminhtml\Support;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Controller\ResultFactory;
use Exception;

class UpdateOrder extends Action
{
    const ADMIN_RESOURCE = 'CarpetAvenue_Developify::support';

    public function __construct(
        Context $context,
        protected ResourceConnection $resourceConnection
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        try {
            $data = $this->getRequest()->getPostValue();
            $type = $data['type'] ?? null;
            if (!$type) {
                throw new Exception('Update Type is required.');
            }
            if (!in_array($type, ['order_status', 'order_customer_group'])) {
                throw new Exception('Invalid Update Type provided.');
            }
            $orderIncrementId = $data['increment_id'] ?? null;
            if (!$orderIncrementId) {
                throw new Exception('Order Increment ID is required.');
            }
            if ($type == 'order_status') {
                $this->updateOrderStatus($orderIncrementId, $data);
            } else {
                $this->updateOrderCustomerGroup($orderIncrementId, $data);
            }
        } catch (Exception $e) {
            $this->messageManager->addErrorMessage(__('Error updating order status: %1', $e->getMessage()));
        }
        return $this->resultFactory->create(
            ResultFactory::TYPE_REDIRECT
        )->setPath('*/*/index');
    }

    /**
     * @param string $orderIncrementId
     * @param array $data
     * @return void
     * @throws Exception
     */
    private function updateOrderStatus(string $orderIncrementId, array $data): void
    {
        $orderStatus = $data['order_status'] ?? null;
        if (!$orderStatus) {
            throw new Exception('Order Status is required.');
        }
        $connection = $this->resourceConnection->getConnection();
        $stateQuery = $connection->select()
            ->from(
                $this->resourceConnection->getTableName('sales_order_status_state'),
                ['state']
            )
            ->where('status = ?', $orderStatus)
            ->limit(1);
        $state = $connection->fetchOne($stateQuery);
        if (!$state) {
            throw new Exception('Invalid Order Status provided.');
        }

        //-- update sales table
        $connection->update(
            $this->resourceConnection->getTableName('sales_order'),
            [
                'status' => $orderStatus,
                'state' => $state
            ],
            ['increment_id = ?' => $orderIncrementId]
        );
        //-- update sales grid table
        $connection->update(
            $this->resourceConnection->getTableName('sales_order_grid'),
            [
                'status' => $orderStatus
            ],
            ['increment_id = ?' => $orderIncrementId]
        );

        $this->messageManager->addSuccessMessage(__('Order %1 status updated successfully.', $orderIncrementId));
    }

    /**
     * @param string $orderIncrementId
     * @param array $data
     * @return void
     * @throws Exception
     */
    private function updateOrderCustomerGroup(string $orderIncrementId, array $data): void
    {
        $customerGroupId = $data['customer_group_id'] ?? null;
        if (!$customerGroupId) {
            throw new Exception('Customer Group ID is required.');
        }
        $connection = $this->resourceConnection->getConnection();
        //-- update sales table
        $connection->update(
            $this->resourceConnection->getTableName('sales_order'),
            [
                'customer_group_id' => $customerGroupId
            ],
            ['increment_id = ?' => $orderIncrementId]
        );
        $this->messageManager->addSuccessMessage(__('Order %1 customer group updated successfully.', $orderIncrementId));

    }
}
