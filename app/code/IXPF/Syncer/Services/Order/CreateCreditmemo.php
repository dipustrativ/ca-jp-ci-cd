<?php
declare(strict_types=1);

namespace IXPF\Syncer\Services\Order;

use Exception;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Sales\Api\CreditmemoManagementInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\CreditmemoFactory;
use Magento\Sales\Model\Order\Email\Sender\CreditmemoSender;

class CreateCreditmemo
{
    public function __construct(
        private readonly OrderRepositoryInterface    $orderRepository,
        private readonly CreditmemoFactory            $creditmemoFactory,
        private readonly CreditmemoManagementInterface $creditmemoManagement,
        private readonly CreditmemoSender             $creditmemoSender,
    ) {
    }

    /**
     * @param int   $orderId
     * @param array $payload
     * @return array
     * @throws Exception
     */
    public function execute(int $orderId, array $payload): array
    {
        try {
            $order = $this->orderRepository->get($orderId);
        } catch (NoSuchEntityException) {
            throw new Exception("Order #$orderId not found");
        }

        if (!in_array($order->getState(), [Order::STATE_PROCESSING, Order::STATE_COMPLETE], true)) {
            throw new Exception(
                "Order #{$order->getIncrementId()} credit memo can only be created in the 'processing' or 'complete' state. "
                . "Current state: {$order->getState()}, status: {$order->getStatus()}"
            );
        }

        if (!$order->canCreditmemo()) {
            throw new Exception(
                "Order #{$order->getIncrementId()} cannot be refunded. "
                . "State: {$order->getState()}, status: {$order->getStatus()}"
            );
        }

        $data = $this->prepareCreditmemoData($order, $payload);

        // Create creditmemo from invoice if invoice_id provided, otherwise from order
        if (!empty($payload['invoice_id'])) {
            $invoice = $this->findInvoice($order, (int) $payload['invoice_id']);
            $creditmemo = $this->creditmemoFactory->createByInvoice($invoice, $data);
        } else {
            $creditmemo = $this->creditmemoFactory->createByOrder($order, $data);
        }

        $hasAdjustments = ($creditmemo->getShippingAmount() > 0)
            || ($creditmemo->getAdjustmentPositive() > 0)
            || ($creditmemo->getAdjustmentNegative() > 0);

        if (!$creditmemo || (!$creditmemo->getTotalQty() && !$hasAdjustments)) {
            throw new Exception(
                "Cannot create credit memo without items or adjustments for order #{$order->getIncrementId()}"
            );
        }

        // Flag items to return qty to salable source stock (MSI/legacy).
        // Without back_to_stock the refund observer never returns qty to source.
        $this->applyBackToStock($creditmemo, $payload);

        // Offline refund by default. Parse "false"/"0"/0/false all as false.
        $offlineRequested = filter_var(
            $payload['offline'] ?? true,
            FILTER_VALIDATE_BOOLEAN,
            FILTER_NULL_ON_FAILURE
        ) ?? true;

        // Online refund needs an invoice captured online through the gateway.
        if (!$offlineRequested && empty($payload['invoice_id'])) {
            throw new Exception(
                "Online refund for order #{$order->getIncrementId()} needs 'invoice_id'. "
                . "Without invoice, only offline refund possible."
            );
        }

        $this->creditmemoManagement->refund($creditmemo, $offlineRequested);

        // Send email if requested
        $notify = !empty($payload['notify']);
        if ($notify) {
            $this->creditmemoSender->send($creditmemo);
        }

        return [
            'order_id'                  => $orderId,
            'increment_id'              => $order->getIncrementId(),
            'creditmemo_id'             => (int) $creditmemo->getEntityId(),
            'creditmemo_increment_id'   => $creditmemo->getIncrementId(),
            'grand_total'               => (float) $creditmemo->getGrandTotal(),
            'total_qty'                 => (float) $creditmemo->getTotalQty(),
            'adjustment_positive'       => (float) $creditmemo->getAdjustmentPositive(),
            'adjustment_negative'       => (float) $creditmemo->getAdjustmentNegative(),
            'state'                     => $order->getState(),
            'status'                    => $order->getStatus(),
            'offline'                   => $offlineRequested,
            'notified'                  => $notify,
            'back_to_stock'             => filter_var(
                $payload['back_to_stock'] ?? true,
                FILTER_VALIDATE_BOOLEAN,
                FILTER_NULL_ON_FAILURE
            ) ?? true,
        ];
    }

    /**
     * Build creditmemo data array from payload.
     * Accepts order_item_id or sku to identify items.
     */
    private function prepareCreditmemoData(Order $order, array $payload): array
    {
        $data = [];

        if (!empty($payload['items']) && is_array($payload['items'])) {
            $skuToItemId = $this->buildSkuMap($order);
            $qtys = [];
            foreach ($payload['items'] as $item) {
                $orderItemId = null;
                if (!empty($item['order_item_id'])) {
                    $orderItemId = (int) $item['order_item_id'];
                } elseif (!empty($item['sku'])) {
                    $orderItemId = $skuToItemId[$item['sku']] ?? null;
                    if (!$orderItemId) {
                        throw new Exception("SKU '{$item['sku']}' not found in order #{$order->getIncrementId()}");
                    }
                }
                if ($orderItemId && isset($item['qty'])) {
                    $qtys[$orderItemId] = (float) $item['qty'];
                }
            }
            $data['qtys'] = $qtys;
        } else {
            // No items specified — set all item qtys to 0 so only shipping/adjustments are refunded
            $hasShippingOrAdjustment = isset($payload['shipping_amount'])
                || isset($payload['adjustment_positive'])
                || isset($payload['adjustment_negative']);

            if ($hasShippingOrAdjustment) {
                $qtys = [];
                foreach ($order->getAllItems() as $item) {
                    $qtys[(int) $item->getItemId()] = 0;
                }
                $data['qtys'] = $qtys;
            }
        }

        if (isset($payload['shipping_amount'])) {
            $data['shipping_amount'] = (float) $payload['shipping_amount'];
        }

        if (isset($payload['adjustment_positive'])) {
            $data['adjustment_positive'] = (float) $payload['adjustment_positive'];
        }

        if (isset($payload['adjustment_negative'])) {
            $data['adjustment_negative'] = (float) $payload['adjustment_negative'];
        }

        return $data;
    }

    /**
     * Set back_to_stock flag on creditmemo items so refund returns qty to salable source.
     *
     * Default: return all items to stock. Override globally with payload['back_to_stock']=false,
     * or per item with items[].back_to_stock (identified by order_item_id or sku).
     */
    private function applyBackToStock(Order\Creditmemo $creditmemo, array $payload): void
    {
        $default = filter_var(
            $payload['back_to_stock'] ?? true,
            FILTER_VALIDATE_BOOLEAN,
            FILTER_NULL_ON_FAILURE
        ) ?? true;

        // Per-item overrides keyed by order_item_id.
        $perItem = [];
        if (!empty($payload['items']) && is_array($payload['items'])) {
            $skuToItemId = $this->buildSkuMap($creditmemo->getOrder());
            foreach ($payload['items'] as $item) {
                if (!array_key_exists('back_to_stock', $item)) {
                    continue;
                }
                $orderItemId = null;
                if (!empty($item['order_item_id'])) {
                    $orderItemId = (int) $item['order_item_id'];
                } elseif (!empty($item['sku'])) {
                    $orderItemId = $skuToItemId[$item['sku']] ?? null;
                }
                if ($orderItemId) {
                    $perItem[$orderItemId] = filter_var(
                        $item['back_to_stock'],
                        FILTER_VALIDATE_BOOLEAN,
                        FILTER_NULL_ON_FAILURE
                    ) ?? $default;
                }
            }
        }

        foreach ($creditmemo->getAllItems() as $cmItem) {
            if ($cmItem->getQty() <= 0) {
                continue;
            }
            $orderItemId = (int) $cmItem->getOrderItemId();
            $cmItem->setBackToStock($perItem[$orderItemId] ?? $default);
        }
    }

    private function buildSkuMap(Order $order): array
    {
        $map = [];
        foreach ($order->getAllItems() as $item) {
            $map[$item->getSku()] = (int) $item->getItemId();
        }
        return $map;
    }

    /**
     * @throws Exception
     */
    private function findInvoice(Order $order, int $invoiceId): Order\Invoice
    {
        foreach ($order->getInvoiceCollection() as $invoice) {
            if ((int) $invoice->getEntityId() === $invoiceId) {
                return $invoice;
            }
        }
        throw new Exception("Invoice #$invoiceId not found for order #{$order->getIncrementId()}");
    }
}
