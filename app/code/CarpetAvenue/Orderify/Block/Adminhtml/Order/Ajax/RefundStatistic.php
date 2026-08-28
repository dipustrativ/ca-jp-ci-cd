<?php

declare(strict_types=1);

namespace CarpetAvenue\Orderify\Block\Adminhtml\Order\Ajax;

use CarpetAvenue\Orderify\Api\BlacklistInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Data\Form\FormKey;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\View\Element\Template;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Framework\Module\Manager as ModuleManager;
use Zend_Db_Expr;

class RefundStatistic extends Template
{
    public function __construct(
        Template\Context                   $context,
        protected OrderRepositoryInterface $orderRepository,
        protected ResourceConnection       $resourceConnection,
        protected ModuleManager $moduleManager,
        protected BlacklistInterface $blacklistService,
        protected FormKey $_formKey,
        array $data = []
    )
    {
        parent::__construct($context, $data);
    }

    public function getOrderId(): int
    {
        return intval($this->getRequest()->getParam('order_id'));
    }

    public function getOrder(): OrderInterface
    {
        $orderId = $this->getOrderId();
        return $this->orderRepository->get($orderId);
    }

    public function getRefundStatisticByEmail(?string $email = null): array
    {
        if ($email === null) {
            return [];
        }

        $connection = $this->resourceConnection->getConnection();
        $orderTable = $this->resourceConnection->getTableName('sales_order');
        $creditMemoTable = $this->resourceConnection->getTableName('sales_creditmemo');
        $select = $connection->select()
            ->from(
                ['so' => $orderTable],
                [
                    'customer_email' => 'so.customer_email',
                    'total_refund' => new Zend_Db_Expr('COUNT(DISTINCT so.entity_id)'),
                    'all_refund_orders' => new Zend_Db_Expr(
                        "GROUP_CONCAT(DISTINCT CONCAT(so.increment_id, '--', so.entity_id) ORDER BY so.entity_id DESC SEPARATOR ', ')"
                    )
                ]
            )
            ->join(
                ['sc' => $creditMemoTable],
                'so.entity_id = sc.order_id',
                []
            )
            ->where('so.customer_email = ?', $email)
            ->group('so.customer_email')
            ->order('total_refund DESC');
        $results = $connection->fetchRow($select);
        if (!empty($results)) {
            return $results;
        }
        return [];
    }

    public function getRefundStatisticByStreetAddress(?string $address = null): array
    {
        if ($address === null) {
            return [];
        }

        $connection = $this->resourceConnection->getConnection();
        $orderTable = $this->resourceConnection->getTableName('sales_order');
        $creditMemoTable = $this->resourceConnection->getTableName('sales_creditmemo');
        $tableAddress = $this->resourceConnection->getTableName('sales_order_address');
        $select = $connection->select()
            ->from(['sc' => $creditMemoTable], [])
            ->join(
                ['so' => $orderTable],
                'so.entity_id = sc.order_id',
                [
                    'total_refund' => new Zend_Db_Expr('COUNT(DISTINCT so.entity_id)'),
                    'all_refund_orders' => new Zend_Db_Expr(
                        "GROUP_CONCAT(DISTINCT CONCAT(so.increment_id, '--', so.entity_id) ORDER BY so.entity_id DESC SEPARATOR ', ')"
                    )
                ]
            )
            ->join(
                ['cae' => $tableAddress],
                'cae.entity_id = so.shipping_address_id',
                ['street']
            )
            ->where('cae.street = ?', $address)
            ->group('cae.street')
            ->order('total_refund DESC');
        $results = $connection->fetchRow($select);

        if (!empty($results)) {
            return $results;
        }
        return [];
    }

    public function getRefundStatisticByIP(?string $ip = null): array
    {
        if ($ip === null) {
            return [];
        }

        $connection = $this->resourceConnection->getConnection();
        $orderTable = $this->resourceConnection->getTableName('sales_order');
        $creditMemoTable = $this->resourceConnection->getTableName('sales_creditmemo');
        $select = $connection->select()
            ->from(['sc' => $creditMemoTable], [])
            ->join(
                ['so' => $orderTable],
                'so.entity_id = sc.order_id',
                [
                    'ip' => new Zend_Db_Expr("SUBSTRING_INDEX(so.x_forwarded_for, ',', 1)"),
                    'total_refund' => new Zend_Db_Expr('COUNT(DISTINCT so.entity_id)'),
                    'all_refund_orders' => new Zend_Db_Expr(
                        "GROUP_CONCAT(DISTINCT CONCAT(so.increment_id, '--', so.entity_id) ORDER BY so.entity_id DESC SEPARATOR ', ')"
                    )
                ]
            )
            ->where("SUBSTRING_INDEX(so.x_forwarded_for, ',', 1) = ?", $ip)
            ->group(new Zend_Db_Expr("SUBSTRING_INDEX(so.x_forwarded_for, ',', 1)"))
            ->order('total_refund DESC');
        $results = $connection->fetchRow($select);
        if (!empty($results)) {
            return $results;
        }
        return [];
    }

    public function getBlackList(): array
    {
        $orderId = $this->getOrderId();
        return $this->blacklistService->getBlackListFromOrder($orderId);

    }

    public function getOrderUrl($orderId): string
    {
        if ($this->moduleManager->isEnabled('IXPF_Syncer')) {
            return $this->getUrl('ixpfsyncer/forward/index/order/', ['order' => $orderId]);
        }
        return $this->getUrl('sales/order/view/', ['order_id' => $orderId]);
    }

    /**
     * @return string
     */
    public function getAddToBlackListUrl(): string
    {
        return $this->getUrl('orderify/blacklist/add');
    }

    /**
     * @throws LocalizedException
     */
    public function getFormKey(): string
    {
        return $this->_formKey->getFormKey();
    }

    public function getBlackListRemoveUrl(int|string $id): string
    {
        return $this->getUrl('orderify/blacklist/remove', ['id' => $id]);
    }
}