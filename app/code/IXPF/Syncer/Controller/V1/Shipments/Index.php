<?php

declare(strict_types=1);

namespace IXPF\Syncer\Controller\V1\Shipments;

use IXPF\Syncer\Controller\AbstractController;
use IXPF\Syncer\Helper\TokenValidator;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\App\ResponseInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Sales\Model\ResourceModel\Order\Shipment\CollectionFactory as ShipmentCollectionFactory;

class Index extends AbstractController
{

    public function __construct(
        Context                                $context,
        TokenValidator                         $tokenValidator,
        JsonFactory                            $resultFactory,
        protected ShipmentCollectionFactory  $shipmentCollectionFactory,
        protected ResourceConnection           $resourceConnection,
        protected ProductRepositoryInterface   $productRepository
    ) {
        parent::__construct($context, $tokenValidator, $resultFactory);
    }

    public function execute(): Json|ResultInterface|ResponseInterface
    {
        try {
            //-- token validation check
            $this->tokenValidator();
            $connection = $this->resourceConnection->getConnection();

            if ($creditMemoId = $this->getRequest()->getParam('shipment_id')) {
                $shipmentCollection = $this->shipmentCollectionFactory->create();
                $shipmentCollection->addFieldToFilter('entity_id', $creditMemoId);
                $shipment = $shipmentCollection->getFirstItem();
                return $this->jsonResponse($this->getShipment($shipment, $connection), 200);
            }

            $page = $this->getRequest()->getParam('page', 1);
            $pageSize = $this->getRequest()->getParam('limit', 10);
            $paramFromId = $this->getRequest()->getParam('from_id');
            $paramToId = $this->getRequest()->getParam('to_id');
            $paramFromDate = $this->getRequest()->getParam('from_date');
            $paramToDate = $this->getRequest()->getParam('to_date');
            $paramLatestCreditMemo = $this->getRequest()->getParam('latest');

            $shipmentCollection = $this->shipmentCollectionFactory->create();
            if ($paramFromId > 0 && $paramToId > $paramFromId) {
                $shipmentCollection->addFieldToFilter('entity_id', ['gteq' => $paramFromId]);
                $shipmentCollection->addFieldToFilter('entity_id', ['lteq' => $paramToId]);
            } elseif ($paramFromDate && $paramToDate) {
                // Normalize date-only strings to include full day boundary
                $fromDate = (strlen($paramFromDate) === 10) ? $paramFromDate . ' 00:00:00' : $paramFromDate;
                $toDate   = (strlen($paramToDate)   === 10) ? $paramToDate   . ' 23:59:59' : $paramToDate;
                $shipmentCollection->addFieldToFilter('updated_at', ['gteq' => $fromDate]);
                $shipmentCollection->addFieldToFilter('updated_at', ['lteq' => $toDate]);
                $shipmentCollection->setPageSize($pageSize);
                $shipmentCollection->setCurPage($page);
            } elseif ($paramLatestCreditMemo) {
                $shipmentCollection->addFieldToFilter('updated_at', ['gteq' => $paramLatestCreditMemo]);
                $shipmentCollection->setPageSize($pageSize);
                $shipmentCollection->setCurPage($page);
            } else {
                $shipmentCollection->setPageSize($pageSize);
                $shipmentCollection->setCurPage($page);
            }
            $shipmentCollection->setOrder('created_at', 'DESC');
            $totalCount = $shipmentCollection->getSize();

            $items = [];
            foreach ($shipmentCollection as $shipment) {
                $shipmentData = $this->getShipment($shipment, $connection);
                if ($shipmentData) {
                    $items[] = $shipmentData;
                }
            }

            if ($paramFromId > 0 && $paramToId > $paramFromId) {
                $pageSize = $totalCount;
            }

            return $this->jsonResponse([
                'items' => $items,
                'total_count' => intval($totalCount),
                'current_page' => intval($page),
                'page_size' => intval($pageSize),
            ], 200);

        } catch (\Exception $exception) {
            return $this->jsonResponse(['error' => $exception->getMessage()], 400);
        }
    }

    private function getShipment($shipment, $connection)
    {

        try {
            $order = $shipment->getOrder();
            $shipmentData = [
                'entity_id' => intval($shipment->getEntityId()),
                'increment_id' => strval($shipment->getIncrementId()),
                'store_id' => intval($shipment->getStoreId()),
                'store' => $order->getStoreName(),
                'order_id' => intval($shipment->getOrderId()),
                'state' => intval($order->getState()),
                'order_currency_code' => $order->getOrderCurrencyCode(),
                'base_to_global_rate' => doubleval($order->getBaseToGlobalRate()),
                'store_to_eur_rate' => doubleval($order->getStoreToEurRate()),
                'adjustment' => doubleval($order->getAdjustment()),
                'discount_tax_compensation_amount' => floatval($order->getDiscountTaxCompensationAmount()),
                'shipping_discount_tax_compensation_amount' => floatval($order->getShippingDiscountTaxCompensationAmount()),
                'discount_amount' => floatval($order->getDiscountAmount()),
                'shipping_amount' => floatval($order->getShippingAmount()),
                'shipping_tax_amount' => floatval($order->getShippingTaxAmount()),
                'shipping_incl_tax' => floatval($order->getShippingInclTax()),
                'tax_amount' => floatval($order->getTaxAmount()),
                'subtotal' => floatval($order->getSubtotal()),
                'subtotal_incl_tax' => floatval($order->getSubtotalInclTax()),
                'grand_total' => floatval($order->getGrandTotal()),
                'order_date' => $order->getCreatedAt(),
                'coupon_code' => $order->getCouponCode(),
                'is_pos_order' => intval($order->getIsPosOrder()),
                'comments' => $this->getComments($connection, $shipment->getEntityId()),
                'shipping_method' => strval($order->getShippingMethod()),
                'shipping_description' => strval($order->getShippingDescription()),
                'tracks' => $this->getTracks($connection, $shipment->getEntityId()),
                'inventory' => $this->getInventorySource($connection, $shipment->getEntityId()),
                'created_at' => $shipment->getCreatedAt(),
                'updated_at' => $shipment->getUpdatedAt(),
            ];

            $shipmentItems = [];
            $appliedTaxes = [];
            //-- invoice items
            foreach ($shipment->getItems() as $item) {
                $itemData = [
                    'sku' => $item->getSku(),
                    'name' => $item->getName(),
                    'default_name' => $item->getName(),
                    'price' => floatval($item->getPrice()),
                    'price_incl_tax' => floatval($item->getPriceInclTax()),
                    'tax_amount' => floatval($item->getTaxAmount()),
                    'discount_amount' => floatval($item->getDiscountAmount()),
                    'product_id' => intval($item->getProductId()),
                    'order_item_id' => intval($item->getOrderItemId()),
                    'qty' => floatval($item->getQty()),
                    'row_total_incl_tax' => floatval($item->getRowTotalInclTax()),
                    'cost' => floatval($item->getData('base_cost') ?: 0),
                ];

                //-- get the product original name
                $product = $this->getProductInformation($item->getProductId());
                if ($product) {
                    $itemData['default_name'] = $product->getName();
                }

                //-- get the applied tax details
                $taxDetails = $connection->fetchRow(
                    "SELECT SOTI.tax_percent, SOT.code as tax_code, SOT.title as tax_title
                            FROM sales_order_tax_item AS SOTI
                             INNER JOIN sales_order_tax AS SOT ON SOT.tax_id = SOTI.tax_id
                             WHERE SOTI.item_id = ?",
                    [$item->getOrderItemId()]
                );
                if ($taxDetails) {
                    $percent = floatval($taxDetails['tax_percent']);
                    $taxAmount = floatval($item->getTaxAmount() ?: 0);

                    $taxDetails['tax_percent'] = $percent;
                    $itemData['tax_details'] = $taxDetails;
                    $key = (string)$percent;
                    if (!isset($appliedTaxes[$key])) {
                        $taxDetails['amount'] = 0;
                        $appliedTaxes[$key] = $taxDetails;
                    }

                    $appliedTaxes[$key]['amount'] += $taxAmount;
                }

                //-- get product original price
                $itemData['original_price'] = $itemData['price'];
                if ($item->getData('order_item_id')) {
                    $orderItem = $connection->fetchRow(
                        "SELECT original_price
                            FROM sales_order_item
                            WHERE item_id = ?",
                        [$item->getData('order_item_id')]
                    );
                    if ($orderItem) {
                        $itemData['original_price'] = floatval($orderItem['original_price']);
                    }
                }

                $shipmentItems[] = $itemData;
            }
            $shipmentData['items'] = $shipmentItems;



            return $shipmentData;
        } catch (\Exception $exception) {
            return [];
        }
    }

    private function getProductInformation($productId): ?ProductInterface
    {
        try {
            return $this->productRepository->getById($productId, false, 0);
        } catch (\Exception $exception) {
            return null;
        }
    }

    private function getComments($connection, $shipmentId)
    {
        try {
            $comments = $connection->fetchAll(
                "SELECT comment, created_at,is_customer_notified, is_visible_on_front  FROM sales_shipment_comment WHERE parent_id = ?",
                [$shipmentId]
            );
            return $comments ?: [];
        } catch (\Exception $exception) {
            return [];
        }
    }

    private function getTracks($connection, $shipmentId)
    {
        try {
            $tracks = $connection->fetchAll(
                "SELECT entity_id, track_number, title, carrier_code, description, weight, qty, created_at
                    FROM sales_shipment_track WHERE parent_id = ?",
                [$shipmentId]
            );
            return array_map(static function ($track) {
                return [
                    'entity_id' => intval($track['entity_id']),
                    'track_number' => strval($track['track_number']),
                    'title' => strval($track['title']),
                    'carrier_code' => strval($track['carrier_code']),
                    'description' => strval($track['description']),
                    'weight' => floatval($track['weight']),
                    'qty' => floatval($track['qty']),
                    'created_at' => $track['created_at'],
                ];
            }, $tracks ?: []);
        } catch (\Exception $exception) {
            return [];
        }
    }

    private function getInventorySource($connection, $shipmentId)
    {
        try {
            $source = $connection->fetchOne(
                "SELECT isr.name
                    FROM inventory_shipment_source AS iss
                    INNER JOIN inventory_source AS isr ON isr.source_code = iss.source_code
                    WHERE iss.shipment_id = ?",
                [$shipmentId]
            );
            return $source ?: '';
        } catch (\Exception $exception) {
            return '';
        }
    }
}
