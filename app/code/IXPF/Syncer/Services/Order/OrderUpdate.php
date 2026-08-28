<?php
declare(strict_types=1);

namespace IXPF\Syncer\Services\Order;

use Exception;
use IXPF\Syncer\Helper\Logger\OrderActionLogger;
use Magento\Customer\Api\AddressRepositoryInterface;
use Magento\Customer\Api\Data\AddressInterfaceFactory;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Sales\Api\Data\OrderAddressInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Email\Sender\OrderCommentSender;
use Magento\Sales\Model\ResourceModel\Order\Status\CollectionFactory as OrderStatusCollectionFactory;

class OrderUpdate
{
    public function __construct(
        protected readonly OrderRepositoryInterface     $orderRepository,
        protected readonly AddressRepositoryInterface   $customerAddressRepository,
        protected readonly AddressInterfaceFactory      $customerAddressFactory,
        protected readonly OrderStatusCollectionFactory $orderStatusCollectionFactory,
        protected readonly OrderActionLogger           $orderLogger,
        protected readonly OrderCommentSender          $orderCommentSender
    )
    {
    }

    /**
     * @throws Exception
     */
    public function process(int $orderId, array $payload): array
    {
        try {
            $order = $this->orderRepository->get($orderId);
        } catch (NoSuchEntityException) {
            throw new Exception("Order #$orderId not found");
        }

        $updated = [];

        if (!empty($payload['status'])) {
            $this->updateStatus($order, $payload['status']);
            $updated[] = 'status';
        }

        if (!empty($payload['billing_address'])) {
            $this->updateOrderAddress($order->getBillingAddress(), $payload['billing_address']);
            $updated[] = 'billing_address';
        }

        if (!empty($payload['shipping_address'])) {
            $shippingAddress = $order->getShippingAddress();
            if ($shippingAddress) {
                $this->updateOrderAddress($shippingAddress, $payload['shipping_address']);
                $updated[] = 'shipping_address';

                $customerId = (int)$order->getCustomerId();
                if ($customerId > 0) {
                    $existingAddressId = (int)$shippingAddress->getCustomerAddressId();

                    if ($existingAddressId > 0) {
                        $this->syncCustomerAddress($customerId, $shippingAddress, $payload['shipping_address']);
                        $updated[] = 'customer_address';
                    }
                }
            }
        }

        $this->orderRepository->save($order);

        $this->orderLogger->writeDaily(
            "[order_update] Order ID: $orderId updated — fields: "
            . (empty($updated) ? 'none' : implode(', ', $updated))
        );

        return ['updated_fields' => $updated, 'order_id' => $orderId];
    }

    /**
     * @throws Exception
     */
    private function updateStatus(Order $order, string $statusCode): void
    {
        $statusCode = strtolower(trim($statusCode));

        // Handle cancellation — uses built-in cancel() which:
        // - restores reserved qty back to stock (via MSI or legacy CatalogInventory)
        // - sets qty_canceled on each order item
        // - triggers sales_order_cancel_after event
        if ($statusCode === 'canceled') {
            if (!$order->canCancel()) {
                throw new Exception(
                    "Order #{$order->getIncrementId()} cannot be cancelled in its current state '{$order->getStatus()}'."
                );
            }
            $order->cancel();
            $order->addCommentToStatusHistory(
                "Order cancelled via IXPF API",
                Order::STATE_CANCELED,
                false
            );
            $this->orderLogger->writeDaily(
                "[order_update] Order #{$order->getIncrementId()} cancelled via status update"
            );
            return;
        }

        // Load from sales_order_status_state to get the state mapped to this status
        $collection = $this->orderStatusCollectionFactory->create();
        $collection->joinStates();
        $collection->addFieldToFilter('main_table.status', $statusCode);

        /** @var \Magento\Sales\Model\Order\Status $statusModel */
        $statusModel = $collection->getFirstItem();

        if (!$statusModel || !$statusModel->getStatus()) {
            throw new Exception(
                "Order status '$statusCode' does not exist in Magento. 
            Please create it under Stores > Order Status first."
            );
        }

        $state = $statusModel->getState();

        $previousStatus = $order->getStatus();
        $order->setState($state);
        $order->setStatus($statusCode);
        $order->addCommentToStatusHistory(
            "Status updated to '$statusCode' (state: $state) via IXPF API",
            $statusCode,
            false
        );
        $this->orderLogger->writeDaily(
            "[order_update] Order #{$order->getIncrementId()} status changed from '$previousStatus' to '$statusCode' (state: $state)"
        );
    }

    /**
     * Update order status and add a comment to status history.
     *
     * @throws Exception
     */
    public function addComment(int $orderId, array $payload): array
    {
        try {
            $order = $this->orderRepository->get($orderId);
        } catch (NoSuchEntityException) {
            throw new Exception("Order #$orderId not found");
        }

        $comment = trim($payload['comment'] ?? '');
        $status = trim($payload['status'] ?? '');
        $visibleOnStorefront = !empty($payload['visible_storefront']);
        $notifyCustomer = !empty($payload['notify_customer']);

        if (empty($comment) && empty($status)) {
            throw new Exception("At least 'comment' or 'status' is required.");
        }

        // If status provided, update state + status
        if (!empty($status)) {
            $status = strtolower($status);

            $collection = $this->orderStatusCollectionFactory->create();
            $collection->joinStates();
            $collection->addFieldToFilter('main_table.status', $status);

            /** @var \Magento\Sales\Model\Order\Status $statusModel */
            $statusModel = $collection->getFirstItem();

            if (!$statusModel || !$statusModel->getStatus()) {
                throw new Exception(
                    "Order status '$status' does not exist in Magento. "
                    . "Please create it under Stores > Order Status first."
                );
            }

            $state = $statusModel->getState();

            // Block processing/complete state if no invoice exists
            $invoiceRequired = [Order::STATE_PROCESSING, Order::STATE_COMPLETE];
            if (in_array($state, $invoiceRequired) && !$order->hasInvoices()) {
                throw new Exception(
                    "Cannot update order #{$order->getIncrementId()} to '$status' (state: $state). "
                    . "Invoice must be created first."
                );
            }

            $previousStatus = $order->getStatus();
            $order->setState($state);
            $order->setStatus($status);
            $this->orderLogger->writeDaily(
                "[comment] Order #{$order->getIncrementId()} status changed from '$previousStatus' to '$status' (state: $state)"
            );
        }

        $historyComment = !empty($comment)
            ? $comment
            : "Status updated to '$status' via IXPF API";

        $history = $order->addCommentToStatusHistory(
            $historyComment,
            $status ?: false,
            $visibleOnStorefront
        );
        $history->setIsCustomerNotified($notifyCustomer);
        $order->setCustomerNoteNotify($notifyCustomer);

        $this->orderRepository->save($order);

        // Send "order comment" email to customer when requested
        if ($notifyCustomer) {
            try {
                $this->orderCommentSender->send($order, true, $historyComment);
                $this->orderLogger->writeDaily(
                    "[comment] Notification email sent to customer for Order #{$order->getIncrementId()}"
                );
            } catch (Exception $e) {
                $this->orderLogger->writeDaily(
                    "[comment] Failed to send notification email for Order #{$order->getIncrementId()}: "
                    . $e->getMessage(),
                    'error'
                );
            }
        }

        $this->orderLogger->writeDaily(
            "[comment] Comment added to Order #{$order->getIncrementId()} — visible_storefront: "
            . ($visibleOnStorefront ? 'yes' : 'no')
            . ", notify_customer: " . ($notifyCustomer ? 'yes' : 'no')
        );

        return [
            'order_id' => $orderId,
            'increment_id' => $order->getIncrementId(),
            'status' => $order->getStatus(),
            'comment' => $historyComment,
            'visible_on_storefront' => $visibleOnStorefront,
            'notify_customer' => $notifyCustomer,
        ];
    }

    /**
     * Overwrite fields on an order address (billing or shipping).
     */
    private function updateOrderAddress(
        OrderAddressInterface $address,
        array $data
    ): void
    {
        $fieldMap = [
            'firstname' => 'setFirstname',
            'lastname' => 'setLastname',
            'company' => 'setCompany',
            'street' => 'setStreet',
            'city' => 'setCity',
            'region' => 'setRegion',
            'region_id' => 'setRegionId',
            'postcode' => 'setPostcode',
            'country_id' => 'setCountryId',
            'telephone' => 'setTelephone',
            'fax' => 'setFax',
        ];

        foreach ($fieldMap as $key => $setter) {
            if (array_key_exists($key, $data)) {
                $value = $key === 'street' && is_string($data[$key])
                    ? explode("\n", $data[$key])
                    : $data[$key];
                $address->$setter($value);
            }
        }
    }

    /**
     * Find or create a customer address linked to the order shipping address
     * and mark it as the default shipping address.
     *
     * @throws LocalizedException
     */
    private function syncCustomerAddress(
        int                   $customerId,
        OrderAddressInterface $orderAddress,
        array                 $data
    ): void
    {
        $existingAddressId = (int)$orderAddress->getCustomerAddressId();

        if ($existingAddressId <= 0) {
            return;
        }

        try {
            $customerAddress = $this->customerAddressRepository->getById($existingAddressId);
        } catch (NoSuchEntityException) {
            return;
        }

        $fieldSetters = [
            'firstname'  => 'setFirstname',
            'lastname'   => 'setLastname',
            'company'    => 'setCompany',
            'city'       => 'setCity',
            'region_id'  => 'setRegionId',
            'postcode'   => 'setPostcode',
            'country_id' => 'setCountryId',
            'telephone'  => 'setTelephone',
        ];

        foreach ($fieldSetters as $key => $setter) {
            if (array_key_exists($key, $data)) {
                $customerAddress->$setter($data[$key]);
            }
        }

        if (array_key_exists('street', $data)) {
            $street = is_string($data['street']) ? explode("\n", $data['street']) : $data['street'];
            $customerAddress->setStreet($street);
        }

        if (!empty($data['region_id']) || !empty($data['region'])) {
            $region = $customerAddress->getRegion();
            if ($region) {
                if (!empty($data['region_id'])) $region->setRegionId((int)$data['region_id']);
                if (!empty($data['region']))    $region->setRegion($data['region']);
                $customerAddress->setRegion($region);
            }
        }

        $customerAddress->setIsDefaultShipping(true);

        $savedAddress = $this->customerAddressRepository->save($customerAddress);
        $orderAddress->setCustomerAddressId($savedAddress->getId());

        $this->orderLogger->writeDaily(
            "[order_update] Customer address ID {$savedAddress->getId()} synced for customer ID $customerId (default shipping)"
        );
    }
}