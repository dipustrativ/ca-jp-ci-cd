<?php
declare(strict_types=1);

namespace IXPF\Syncer\Services\Order;

use Exception;
use Magento\Framework\DB\TransactionFactory;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Email\Sender\InvoiceSender;
use Magento\Sales\Model\Service\InvoiceService;

class CreateInvoice
{
    public function __construct(
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly InvoiceService           $invoiceService,
        private readonly InvoiceSender            $invoiceSender,
        private readonly TransactionFactory       $transactionFactory,
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

        if ($order->getState() !== Order::STATE_NEW) {
            throw new Exception(
                "Order #{$order->getIncrementId()} can only be invoiced in the 'new' state. "
                . "Current state: {$order->getState()}, status: {$order->getStatus()}"
            );
        }

        if (!$order->canInvoice()) {
            throw new Exception(
                "Order #{$order->getIncrementId()} cannot be invoiced. "
                . "State: {$order->getState()}, status: {$order->getStatus()}"
            );
        }

        // Prepare item quantities — partial or full invoice
        // Accepts either order_item_id or sku to identify items
        $qtys = [];
        if (!empty($payload['items']) && is_array($payload['items'])) {
            $skuToItemId = $this->buildSkuMap($order);
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
        }

        $invoice = $this->invoiceService->prepareInvoice($order, $qtys);

        if (!$invoice || !$invoice->getTotalQty()) {
            throw new Exception(
                "Cannot create invoice without items for order #{$order->getIncrementId()}"
            );
        }

        $captureCase = $payload['capture'] ?? 'offline';
        match ($captureCase) {
            'online'      => $invoice->setRequestedCaptureCase(Order\Invoice::CAPTURE_ONLINE),
            'offline'     => $invoice->setRequestedCaptureCase(Order\Invoice::CAPTURE_OFFLINE),
            'not_capture' => $invoice->setRequestedCaptureCase(Order\Invoice::NOT_CAPTURE),
            default       => throw new Exception(
                "Invalid capture type '$captureCase'. Allowed: online, offline, not_capture"
            ),
        };

        $invoice->register();
        $order->setIsInProcess(true);

        $transaction = $this->transactionFactory->create();
        $transaction->addObject($invoice);
        $transaction->addObject($order);
        $transaction->save();

        // Send invoice email if requested
        $notify = !empty($payload['notify']);
        if ($notify) {
            $this->invoiceSender->send($invoice);
        }

        return [
            'order_id'              => $orderId,
            'increment_id'          => $order->getIncrementId(),
            'invoice_id'            => (int) $invoice->getEntityId(),
            'invoice_increment_id'  => $invoice->getIncrementId(),
            'grand_total'           => (float) $invoice->getGrandTotal(),
            'total_qty'             => (float) $invoice->getTotalQty(),
            'state'                 => $order->getState(),
            'status'                => $order->getStatus(),
            'notified'              => $notify,
        ];
    }

    /**
     * Build SKU → order_item_id map from order items.
     */
    private function buildSkuMap(Order $order): array
    {
        $map = [];
        foreach ($order->getAllItems() as $item) {
            $map[$item->getSku()] = (int) $item->getItemId();
        }
        return $map;
    }
}
