<?php
declare(strict_types=1);

namespace CarpetAvenue\Orderify\Controller\Adminhtml\Order;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\ResourceConnection;
use Exception;
use Magento\Backend\Model\Auth\Session as AdminSession;

class ReadyForHandling extends Action
{
    const ADMIN_RESOURCE = 'CarpetAvenue_Orderify::ready_for_handling';

    public function __construct(
        Context $context,
        protected ResourceConnection $resourceConnection,
        protected AdminSession $adminSession
    )
    {
        parent::__construct($context);
    }

    public function execute()
    {

        try {
            $params = $this->getRequest()->getParams();
            if (empty($params['selected'])) {
                throw new Exception('No orders selected');
            }

            $adminUser = $this->adminSession->getUser();
            $firstName = $adminUser->getFirstName();
            $lastName  = $adminUser->getLastName();
            $email     = $adminUser->getEmail();
            $connection = $this->resourceConnection->getConnection();
            $selectedIds = array_map('intval', (array) ($params['selected'] ?? []));
            $tableName = $this->resourceConnection->getTableName("sales_order");
            $orderQuery = $connection->select()
                ->from(['main_table' => $tableName], ['entity_id', 'increment_id', 'status', 'state'])
                ->joinLeft(
                    ['si' => $this->resourceConnection->getTableName("sales_invoice")],
                    'main_table.entity_id = si.order_id',
                    ['invoice_id' => 'si.entity_id']
                )
                ->where('main_table.entity_id IN (?)', $selectedIds);
            $orders = $connection->fetchAll($orderQuery);

            $warningMessages = [];
            $successMessages = [];
            foreach ($orders as $order) {
                //-- check invoice id is null
                if (!$order['invoice_id']) {
                    $warningMessages[] = 'Order #' . $order['increment_id'] . ' has no invoice, cannot be marked as ready for handling';
                    continue;
                }
                if ($order['status'] === 'ready_for_handling') {
                    $warningMessages[] = 'Order #' . $order['increment_id'] . ' is already marked as ready for handling';
                    continue;
                }
                if ($order['status'] === 'canceled') {
                    $warningMessages[] = 'Order #' . $order['increment_id'] . ' is canceled, cannot be marked as ready for handling';
                    continue;
                }
                if ($order['status'] === 'closed') {
                    $warningMessages[] = 'Order #' . $order['increment_id'] . ' is closed, cannot be marked as ready for handling';
                    continue;
                }

                $connection->update(
                    $tableName,
                    ['status' => 'ready_for_handling', 'state' => 'processing'],
                    ['entity_id = ?' => $order['entity_id']]
                );
                $connection->update(
                    $this->resourceConnection->getTableName("sales_order_grid"),
                    ['status' => 'ready_for_handling'],
                    ['entity_id = ?' => $order['entity_id']]
                );

                $connection->insert(
                    $this->resourceConnection->getTableName("sales_order_status_history"),
                    [
                        'parent_id' => $order['entity_id'],
                        'is_customer_notified' => 0,
                        'is_visible_on_front' => 0,
                        'comment' => '| by' . $firstName . ' ' . $lastName . ' (' . $email . ') <br> bulk action: mark as ready for handling <br>',
                        'status' => 'ready_for_handling',
                        'entity_name' => 'order',
                    ]
                );
                $successMessages[] = 'Order #' . $order['increment_id'] . ' marked as ready for handling';
            }

            if (!empty($warningMessages)) {
                $this->messageManager->addWarningMessage(implode(' | ', $warningMessages));
            }
            if (!empty($successMessages)) {
                $this->messageManager->addSuccessMessage(implode(' | ', $successMessages));
            }
            return $this->_redirect($this->_redirect->getRefererUrl());
        } catch (Exception $e) {
            $this->messageManager->addErrorMessage($e->getMessage());
            return $this->_redirect($this->_redirect->getRefererUrl());
        }
    }

    protected function _isAllowed(): bool
    {
        return $this->_authorization->isAllowed(self::ADMIN_RESOURCE);
    }
}