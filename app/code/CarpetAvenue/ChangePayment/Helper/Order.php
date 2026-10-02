<?php

declare(strict_types=1);

namespace CarpetAvenue\ChangePayment\Helper;

use Exception;
use InvalidArgumentException;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Checkout\Model\SessionFactory;
use Magento\Customer\Model\Session;
use Magento\Customer\Model\Url;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Framework\App\Helper\Context;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Message\ManagerInterface;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Framework\Url\EncoderInterface;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Api\Data\CartItemInterfaceFactory;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\QuoteManagement;
use Magento\Sales\Model\Order as SalesOrder;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\OrderManagementInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\SalesRule\Api\CouponRepositoryInterface;
use Psr\Log\LoggerInterface;
use Magento\SalesRule\Model\ResourceModel\Coupon\Usage as CouponUsageResource;

class Order extends AbstractHelper
{
    const STATUS_PAUSED = 'awaiting_retry';
    private $resultRedirect;

    public function __construct(
        Context                               $context,
        protected OrderManagementInterface    $orderManagement,
        protected OrderRepositoryInterface    $orderRepository,
        protected CartRepositoryInterface     $quoteRepository,
        protected CartItemInterfaceFactory   $cartItemFactory,
        protected ProductRepositoryInterface $productRepository,
        protected QuoteManagement             $quoteManagement,
        protected SessionFactory              $checkoutSession,
        protected RedirectFactory             $redirectFactory,
        protected Data                        $dataHelper,
        protected Session                     $customerSession,
        protected ManagerInterface            $messageManager,
        protected LoggerInterface             $logger,
        protected EncoderInterface            $encoder,
        protected EncryptorInterface          $encryptor,
        protected Json                        $json,
        protected CouponUsageResource $couponUsageResource,
        protected CouponRepositoryInterface $couponRepository,
        protected SearchCriteriaBuilder $searchCriteriaBuilder,
        protected \Magento\Store\Model\StoreManagerInterface $storeManager,
        protected \Magento\Tax\Model\Calculation $taxCalculation,
        protected \Magento\InventoryReservationsApi\Model\ReservationBuilderInterface $reservationBuilder,
        protected \Magento\InventorySales\Model\ResourceModel\GetAssignedStockIdForWebsite $getAssignedStockIdForWebsite,
        protected \Magento\InventoryReservationsApi\Model\AppendReservationsInterface $appendReservations,
        protected \Magento\Framework\Serialize\SerializerInterface $serializer,
        protected \Magento\Framework\App\ResourceConnection $resource,
        protected \Magento\LoginAsCustomerApi\Api\GetLoggedAsCustomerAdminIdInterface $getLoggedAsCustomerAdminId
    )
    {
        parent::__construct($context);
    }
    public function canHoldAndReorder(OrderInterface $order): bool
    {
        // Check if the order can be held and reordered
        return $order->canHold() && in_array($order->getStatus(), $this->dataHelper->getAllowReorderForOrderStatus()) && !$this->newOrderExists($order);
    }

    public function holdAndReorder()
    {
        try {
            $this->resultRedirect = $this->redirectFactory->create();

            if (!$this->dataHelper->isEnabled()) {
                return $this->resultRedirect->setPath('/');
            }
            if (!$this->_request->getParam('params')) {
                throw new InvalidArgumentException('Missing params');
            }

            $requestParams = $this->json->unserialize($this->encryptor->decrypt($this->_request->getParam('params')));
            $this->validateRequestParams($requestParams);

            /** @var \Magento\Sales\Model\Order $order */
            $order = $this->orderRepository->get($requestParams['order_id']);

            if (!$this->canHoldAndReorder($order)) {
                $this->logger->error(__("Order already processing. Order ID: %1", $order->getIncrementId()));
                throw new LocalizedException(__("We encountered an error while trying to hold your order. Please contact us for further assistance."));
            }

            $customerId = null;
            $customerIdMatch = false;
            if ($this->customerSession->isLoggedIn() && $this->customerSession->getCustomerId() == $order->getCustomerId()) {
                $customerId = $order->getCustomerId();
                $customerIdMatch = true;
            } else if ($this->customerSession->isLoggedIn()) {
                $customerId = $this->customerSession->getCustomerId();
            }
            /*
            //If customer is logged in and is not the order owner, log them out
            if ($order->getCustomerId()) {
                if ($this->customerSession->isLoggedIn() && $this->customerSession->getCustomerId() != $order->getCustomerId()) {
                    // If we already logged out the customer, and they log in with another account, we tell them that it's not their order
                    if (isset($requestParams['logged_out']) && $requestParams['logged_out']) {
                        $this->messageManager->addErrorMessage(__("This doesn't seem to be your order. Please contact us for further assistance."));
                        return $this->resultRedirect->setUrl('/');
                    } else {
                        // The customer does not match the orders' customer, log them out to make them log in to the correct account.
                        return $this->logoutCustomer($requestParams);
                    }
                } elseif (!$this->customerSession->isLoggedIn()) {
                    // Send the customer to the login page
                    return $this->logoutCustomer($requestParams);
                }
            } else {
                $this->validateGuestOrder($requestParams, $order);
            }*/
            $this->validateGuestOrder($requestParams, $order);

            
            // Holding Order
            if ($order->getState() === SalesOrder::STATE_HOLDED) {
                // if order is already held, check connect to the customer
                // if so just return the redirect
                return $this->resultRedirect->setUrl($this->_urlBuilder->getUrl($this->getRedirectPath(), []));
            }
            else if ($order->canHold()) {
                $order->addStatusHistoryComment(__('Holding order initiated by customer through the Change Payment link.'), false);
                $order->hold();
                $order->setStatus(self::STATUS_PAUSED);
                $this->orderRepository->save($order);

                // Adjust reservation and create a new quote.
                $reservations = [];
                $stockId = (int)$this->getAssignedStockIdForWebsite->execute($order->getStore()->getWebsite()->getCode());
                foreach ($order->getAllItems() as $item) {
                    // TODO: Make sure is_salable is corrected in inventory_stock_X
                    // This is necessary to ensure that the product can be added to the new quote
                    // This might need to be reversed at a later point? (at processOrphanedOrders?)
                    $this->fixSalableInStock($item->getSku(), $stockId);
                    $reservations[] = $this->reservationBuilder
                        ->setSku($item->getSku())
                        ->setQuantity((float)$item['qty_ordered'])
                        ->setStockId($stockId)
                        ->setMetadata(
                            $this->serializer->serialize(
                                [
                                    'event_type' => 'ca_order_hold_retry',
                                    'object_type' => 'ca_order_hold',
                                    'object_id' => $order->getId(),
                                    'object_increment_id' => $order->getIncrementId()
                                ]
                            )
                        )
                        ->build();
                }
                $this->appendReservations->execute($reservations);
                //$this->createNewQuote($order,  $order->getCustomerId() ?? null);
                $this->createNewQuote($order,  $customerId, $customerIdMatch);

            } else {
                $this->messageManager->addErrorMessage(__('We encountered an error while trying to hold your order while selecting a different payment method. Please contact us for further assistance.'));
            }
            
            
        } catch (LocalizedException $e) {
            $this->messageManager->addErrorMessage($e->getMessage());
            $this->logger->error($e->getMessage());
            $this->logger->error($e->getTraceAsString());
            return $this->resultRedirect->setUrl($this->_urlBuilder->getUrl('/', []));
        } catch (Exception $e) {
            $this->messageManager->addErrorMessage(__('We encountered an error while trying to hold your order. Please contact us for further assistance.'));
            $this->logger->error($e->getMessage());
            $this->logger->error($e->getTraceAsString());
            return $this->resultRedirect->setUrl($this->_urlBuilder->getUrl('/', []));
        }


        return $this->resultRedirect->setUrl($this->_urlBuilder->getUrl($this->getRedirectPath(), []));
    }
    protected function getRedirectPath()
    {
        if ($this->getLoggedAsCustomerAdminId->execute()) {
            return 'checkout/cart';
        }
        return 'onestepcheckout';
    }
    public function newOrderExists(OrderInterface $order): bool
    {
        $connection = $this->resource->getConnection();
        $orderTable = $this->resource->getTableName('sales_order');
        $query = "select count(entity_id) from ".$orderTable." where holded_order_id='".$order->getId()."'";
        $result = $connection->fetchOne($query);
        if(0 < $result){
            return true;
        }
        return false;
    }   
    public function restoreOrder(OrderInterface $order)
    {
        if ($order->getState() === SalesOrder::STATE_HOLDED) {
            if ($order->canUnhold()) {
                $order->unhold();
                $this->orderRepository->save($order);
            }
        }
    }
    public function processOrphanedOrders()
    {
        $connection = $this->resource->getConnection();
        $orderTable = $this->resource->getTableName('sales_order');
        $query = "select entity_id from ".$orderTable." where state='". SalesOrder::STATE_HOLDED ."' and status='". self::STATUS_PAUSED . "'" .
         " and timestampdiff(minute,updated_at,now()) > ". $this->dataHelper->getRevertOriginalPaymentAfter();
        $connection->query($query);
        foreach ($connection->fetchAll($query) as $row) {
            try{
                $order = $this->orderRepository->get($row['entity_id']);
                $this->restoreOrder($order);
            }
            catch (Exception $e) {
                $this->logger->error('Error while canceling order after new order creation: ' . $e->getMessage());
            }
        }
    }

    public function cancelAfterNewOrder(int $orderId)
    {
        try{
            $order = $this->orderRepository->get($orderId);
            if ($order->getState() === SalesOrder::STATE_HOLDED) {
                if ($order->canUnhold()) {
                    $order->unhold();
                    $this->cancelOrder($order);
                }
            }
        }
        catch (Exception $e) {
            $this->logger->error('Error while canceling order after new order creation: ' . $e->getMessage());
        }
    }
    public function deleteRetryReservations($incrementId){
        $connection = $this->resource->getConnection();
        $reservationTable = $this->resource->getTableName('inventory_reservation');
        $query = "delete from ".$reservationTable." where ".
            " JSON_CONTAINS(metadata,'{\"object_type\":\"ca_order_hold\"}')".
            " and JSON_CONTAINS(metadata,'{\"event_type\":\"ca_order_hold_retry\"}')".
            " and JSON_CONTAINS(metadata,'{\"object_increment_id\":\"".$incrementId."\"}')";
        $connection->query($query);
    }

    public function validateRequestParams(array $params)
    {
        if (!isset($params['order_id'])) {
            throw new InvalidArgumentException('Order id is missing');
        }

        if (!isset($params['email'])) {
            throw new InvalidArgumentException('Email is missing');
        }
    }

    private function validateGuestOrder(array $params, OrderInterface $order)
    {
        // Only way to validate is that the link includes the customer email of the order
        if ($params['email'] != $order->getCustomerEmail()) {
            throw new InvalidArgumentException('Email does not match');
        }
    }


    private function createNewQuote(OrderInterface $order, $customerId, $customerIdMatch)
    {
        $session = $this->checkoutSession->create();
        $newQuote = $session->getQuote();
        $newQuote->removeAllItems();

        // Create a new quote and get its ID
        if ($customerId && $customerIdMatch) {
            $quoteId = $this->quoteManagement->createEmptyCartForCustomer($customerId);
        } else {
            $quoteId = $this->quoteManagement->createEmptyCart();
        }

        // Load the Quote object using the quote ID
        /** @var Quote $newQuote */
        $newQuote = $this->quoteRepository->get($quoteId);
        $newQuote->setData('holded_order_id', $order->getId());

        if ($customerIdMatch) {
            $newQuote->setCustomerId($customerId);
            $newQuote->setCustomerIsGuest(0);
            $newQuote->getBillingAddress()->addData($order->getBillingAddress()->getData());
            $newQuote->getShippingAddress()->addData($order->getShippingAddress()->getData());
        }
        else if (null === $customerId) {
            $newQuote->setCustomerIsGuest(1);
            $billingData = $order->getBillingAddress()->getData();
            $shippingData = $order->getShippingAddress()->getData();
            if ($order->getCustomerIsGuest()){
                $newQuote->getBillingAddress()->addData($billingData);
                $newQuote->getShippingAddress()->addData($shippingData);
            } else {
                unset($billingData["entity_id"]);
                unset($shippingData["entity_id"]);
                unset($billingData["parent_id"]);
                unset($shippingData["parent_id"]);
                unset($billingData["customer_address_id"]);
                unset($shippingData["customer_address_id"]);
                unset($billingData["quote_address_id"]);
                unset($shippingData["quote_address_id"]);
                unset($billingData["customer_id"]);
                unset($shippingData["customer_id"]);
                $newQuote->getBillingAddress()->addData($billingData);
                $newQuote->getShippingAddress()->addData($shippingData);
            }
        }
        else if ($customerId) {
            $newQuote->setCustomerId($customerId);
            $newQuote->setCustomerIsGuest(0);
            $newQuote->getBillingAddress()->addData($this->customerSession->getCustomer()->getDefaultBillingAddress()->getData());
            $newQuote->getShippingAddress()->addData($this->customerSession->getCustomer()->getDefaultShippingAddress()->getData());
        }


        /** @var Quote $oldQuote */
        $oldQuote = $this->quoteRepository->get($order->getQuoteId());

        // Add products to the quote
        foreach ($oldQuote->getAllVisibleItems() as $oldItem) {
            // Create a new cart item and set the product and quantity
            $product = $oldItem->getProduct();
            $product->setSalable(true);
            // Set custom price
            $productPrice = $product->getFinalPrice();
            $originalPrice = $oldItem->getPriceInclTax();
            if ($oldQuote->getCustomerGroupId() ==  3){
                $request = $this->taxCalculation->getRateOriginRequest($this->storeManager->getStore());
                $request['product_class_id'] = $product->getTaxClassId();
                $taxInfo = $this->taxCalculation->getResource()->getRateInfo($request);
                $originalPrice = $this->taxCalculation->round($originalPrice * (1 + $taxInfo['value'] / 100));
            }
            
            if ($originalPrice < $productPrice) {
                $newItem = clone $oldItem;
                $newItem->setItemId(null);
                $newItem->setCustomPrice($originalPrice);
                $newItem->setOriginalCustomPrice($originalPrice);
                $newItem->getProduct()->setIsSuperMode(true);
                $newQuote->addItem($newItem);
            } else {
                $newItem = clone $oldItem;
                $newItem->setItemId(null);
                $newItem->getProduct()->setIsSuperMode(true);
                $newQuote->addItem($newItem);
            }
        }

        // Set shipping method and payment method
        $newQuote->getShippingAddress()->setShippingMethod($order->getShippingMethod());

        // Apply the coupon code (if any)
        if ($order->getCouponCode()) {
            $searchCriteria = $this->searchCriteriaBuilder->addFilter('code', $order->getCouponCode())->create();
            $coupons = $this->couponRepository->getList($searchCriteria)->getItems();
            if (!empty($coupons)) {
                $coupon = reset($coupons);
                $coupon->setTimesUsed($coupon->getTimesUsed() - 1);
                $this->couponRepository->save($coupon);
            }

            // Reset coupon usage for customer
            $this->couponUsageResource->updateCustomerCouponTimesUsed($customerId, $order->getCouponCode(), false);

            $newQuote->setCouponCode($order->getCouponCode());
        }


        // Collect totals to ensure everything is calculated correctly
        $newQuote->collectTotals();

        // Save the quote
        $this->quoteRepository->save($newQuote);

        $session->setIsBankTransfer(0);
        $session->setQuoteId($newQuote->getId());
    }
    private function fixSalableInStock($sku, $stockId)
    {
        $connection = $this->resource->getConnection();
        $stockTable = $this->resource->getTableName('inventory_stock_'.$stockId);
        $query = "UPDATE ".$stockTable." SET is_salable = 1 WHERE sku = :sku";
        $connection->query($query, ['sku' => $sku]);

        /*$stockStatusTable = $this->resource->getTableName('cataloginventory_stock_status');
        $query = "UPDATE ".$stockStatusTable." SET stock_status = 1 WHERE product_id = :product_id";
        $connection->query($query, ['product_id' => $productId]);*/
    }

    private function cancelOrder(OrderInterface $order)
    {
        if ($order->canCancel()) {
            $this->orderManagement->cancel($order->getId());
        }
    }

    private function logoutCustomer($requestParams)
    {
        $this->customerSession->logout();
        $refererParams['params'] = $this->encryptor->encrypt($this->json->serialize($requestParams + ['logged_out' => 1]));
        $param['referer'] = $this->encoder->encode($this->_urlBuilder->getUrl('*/*/*', $refererParams));
        return $this->resultRedirect->setUrl($this->_urlBuilder->getUrl(Url::ROUTE_ACCOUNT_LOGIN, $param));
    }
}
