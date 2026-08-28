<?php
declare(strict_types=1);

namespace IXPF\Syncer\Services\Order;

use Exception;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Email\Sender\CreditmemoSender;
use Magento\Sales\Model\Order\Email\Sender\InvoiceSender;
use Magento\Sales\Model\Order\Email\Sender\OrderSender;
use Magento\Sales\Model\Order\Email\Sender\ShipmentSender;

class Email
{
    private const TYPE_ORDER = 'order';
    private const TYPE_INVOICE = 'invoice';
    private const TYPE_SHIPMENT = 'shipment';
    private const TYPE_CREDITMEMO = 'creditmemo';

    private const ALLOWED_TYPES = [
        self::TYPE_ORDER,
        self::TYPE_INVOICE,
        self::TYPE_SHIPMENT,
        self::TYPE_CREDITMEMO,
    ];

    public function __construct(
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly OrderSender              $orderSender,
        private readonly InvoiceSender            $invoiceSender,
        private readonly ShipmentSender           $shipmentSender,
        private readonly CreditmemoSender         $creditmemoSender,
    ) {
    }

    /**
     * Send email for an order.
     *
     * @param int   $orderId
     * @param array $payload ['type' => 'order|invoice|shipment|creditmemo']
     * @return array
     * @throws Exception
     */
    public function send(int $orderId, array $payload): array
    {
        $type = strtolower(trim($payload['type'] ?? self::TYPE_ORDER));

        if (!in_array($type, self::ALLOWED_TYPES, true)) {
            throw new Exception(
                "Invalid email type '$type'. Allowed: " . implode(', ', self::ALLOWED_TYPES)
            );
        }

        try {
            $order = $this->orderRepository->get($orderId);
            if ($type == self::TYPE_ORDER && !in_array($order->getState(), ['new', 'processing', 'closed'])) {
                throw new Exception(
                    "Order #{$order->getIncrementId()} can only be send email in the new, processing and closed state"
                    . "Current state: {$order->getState()}, status: {$order->getStatus()}"
                );
            }
        } catch (NoSuchEntityException) {
            throw new Exception("Order #$orderId not found");
        }

        $sent = match ($type) {
            self::TYPE_ORDER      => $this->sendOrderEmail($order),
            self::TYPE_INVOICE    => $this->sendInvoiceEmail($order),
            self::TYPE_SHIPMENT   => $this->sendShipmentEmail($order),
            self::TYPE_CREDITMEMO => $this->sendCreditmemoEmail($order),
        };

        return [
            'order_id'     => $orderId,
            'increment_id' => $order->getIncrementId(),
            'email_type'   => $type,
            'sent'         => $sent,
        ];
    }

    /**
     * @throws Exception
     */
    private function sendOrderEmail(Order $order): bool
    {
        return $this->orderSender->send($order, true);
    }

    /**
     * @throws Exception
     */
    private function sendInvoiceEmail(Order $order): bool
    {
        $invoice = $order->getInvoiceCollection()->getLastItem();

        if (!$invoice || !$invoice->getId()) {
            throw new Exception(
                "Order #{$order->getIncrementId()} has no invoice. Cannot send invoice email."
            );
        }

        return $this->invoiceSender->send($invoice, true);
    }

    /**
     * @throws Exception
     */
    private function sendShipmentEmail(Order $order): bool
    {
        $shipment = $order->getShipmentsCollection()->getLastItem();

        if (!$shipment || !$shipment->getId()) {
            throw new Exception(
                "Order #{$order->getIncrementId()} has no shipment. Cannot send shipment email."
            );
        }

        return $this->shipmentSender->send($shipment, true);
    }

    /**
     * @throws Exception
     */
    private function sendCreditmemoEmail(Order $order): bool
    {
        $creditmemo = $order->getCreditmemosCollection()->getLastItem();

        if (!$creditmemo || !$creditmemo->getId()) {
            throw new Exception(
                "Order #{$order->getIncrementId()} has no credit memo. Cannot send creditmemo email."
            );
        }

        return $this->creditmemoSender->send($creditmemo, true);
    }
}
