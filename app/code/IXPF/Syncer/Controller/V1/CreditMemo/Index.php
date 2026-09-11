<?php

declare(strict_types=1);

namespace IXPF\Syncer\Controller\V1\CreditMemo;

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
use Magento\Sales\Model\ResourceModel\Order\CreditMemo\CollectionFactory as CreditMemoCollectionFactory;

class Index extends AbstractController
{

    public function __construct(
        Context                                $context,
        TokenValidator                         $tokenValidator,
        JsonFactory                            $resultFactory,
        protected CreditMemoCollectionFactory  $creditMemoCollectionFactory,
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

            if ($creditMemoId = $this->getRequest()->getParam('creditmemo_id')) {
                $creditMemoCollection = $this->creditMemoCollectionFactory->create();
                $creditMemoCollection->addFieldToFilter('entity_id', $creditMemoId);
                $creditMemo = $creditMemoCollection->getFirstItem();
                return $this->jsonResponse($this->getCreditMemo($creditMemo, $connection), 200);
            }

            $page = $this->getRequest()->getParam('page', 1);
            $pageSize = $this->getRequest()->getParam('limit', 10);
            $paramFromId = $this->getRequest()->getParam('from_id');
            $paramToId = $this->getRequest()->getParam('to_id');
            $paramFromDate = $this->getRequest()->getParam('from_date');
            $paramToDate = $this->getRequest()->getParam('to_date');
            $paramLatestCreditMemo = $this->getRequest()->getParam('latest');

            $creditMemoCollection = $this->creditMemoCollectionFactory->create();
            if ($paramFromId > 0 && $paramToId > $paramFromId) {
                $creditMemoCollection->addFieldToFilter('entity_id', ['gteq' => $paramFromId]);
                $creditMemoCollection->addFieldToFilter('entity_id', ['lteq' => $paramToId]);
            } elseif ($paramFromDate && $paramToDate) {
                // Normalize date-only strings to include full day boundary
                $fromDate = (strlen($paramFromDate) === 10) ? $paramFromDate . ' 00:00:00' : $paramFromDate;
                $toDate   = (strlen($paramToDate)   === 10) ? $paramToDate   . ' 23:59:59' : $paramToDate;
                $creditMemoCollection->addFieldToFilter('created_at', ['gteq' => $fromDate]);
                $creditMemoCollection->addFieldToFilter('created_at', ['lteq' => $toDate]);
                $creditMemoCollection->setPageSize($pageSize);
                $creditMemoCollection->setCurPage($page);
            } elseif ($paramLatestCreditMemo) {
                $creditMemoCollection->addFieldToFilter('updated_at', ['gteq' => $paramLatestCreditMemo]);
                $creditMemoCollection->setPageSize($pageSize);
                $creditMemoCollection->setCurPage($page);
            } else {
                $creditMemoCollection->setPageSize($pageSize);
                $creditMemoCollection->setCurPage($page);
            }
            $creditMemoCollection->setOrder('created_at', 'DESC');
            $totalCount = $creditMemoCollection->getSize();

            $items = [];
            foreach ($creditMemoCollection as $creditMemo) {
                $creditMemoData = $this->getCreditMemo($creditMemo, $connection);
                if ($creditMemoData) {
                    $items[] = $creditMemoData;
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

    private function getCreditMemo($creditMemo, $connection)
    {
        try {
            $creditMemoData = [
                'entity_id' => intval($creditMemo->getEntityId()),
                'increment_id' => strval($creditMemo->getIncrementId()),
                'store_id' => intval($creditMemo->getStoreId()),
                'order_id' => intval($creditMemo->getOrderId()),
                'state' => intval($creditMemo->getState()),
                'order_currency_code' => $creditMemo->getOrderCurrencyCode(),
                'base_to_global_rate' => doubleval($creditMemo->getBaseToGlobalRate()),
                'store_to_eur_rate' => doubleval($creditMemo->getStoreToEurRate()),
                'adjustment' => doubleval($creditMemo->getAdjustment()),
                'discount_tax_compensation_amount' => floatval($creditMemo->getDiscountTaxCompensationAmount()),
                'shipping_discount_tax_compensation_amount' => floatval($creditMemo->getShippingDiscountTaxCompensationAmount()),
                'discount_amount' => floatval($creditMemo->getDiscountAmount()),
                'shipping_amount' => floatval($creditMemo->getShippingAmount()),
                'shipping_tax_amount' => floatval($creditMemo->getShippingTaxAmount()),
                'shipping_incl_tax' => floatval($creditMemo->getShippingInclTax()),
                'tax_amount' => floatval($creditMemo->getTaxAmount()),
                'subtotal' => floatval($creditMemo->getSubtotal()),
                'subtotal_incl_tax' => floatval($creditMemo->getSubtotalInclTax()),
                'grand_total' => floatval($creditMemo->getGrandTotal()),
                'order_date' => $creditMemo->getOrder()->getCreatedAt(),
                'coupon_code' => $creditMemo->getOrder()->getCouponCode(),
                'is_pos_order' => intval($creditMemo->getOrder()->getIsPosOrder()),
                'comments' => $this->getComments($connection, $creditMemo->getEntityId()),
                'created_at' => $creditMemo->getCreatedAt(),
                'updated_at' => $creditMemo->getUpdatedAt(),
            ];
            $creditMemoItems = [];
            $creditMemoShipping = [];
            $appliedTaxes = [];
            //-- invoice items
            foreach ($creditMemo->getItems() as $item) {
                $itemData = [
                    'sku' => $item->getSku(),
                    'name' => $item->getName(),
                    'default_name' => $item->getName(),
                    'type' => $this->getType($connection, $item->getSku()),
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

                $creditMemoItems[] = $itemData;
            }

            //-- shipping tax
            if (floatval($creditMemo->getShippingTaxAmount()) > 0) {
                $shippingTaxDetails = $this->getShippingTax($connection, $creditMemo->getOrderId());
                if ($shippingTaxDetails) {
                    $percent = floatval($shippingTaxDetails['tax_percent']);
                    $creditMemoShipping['tax_percent'] = $percent;
                    $key = (string)$percent;
                    if (!isset($appliedTaxes[$key])) {
                        $shippingTaxDetails['amount'] = 0;
                        $appliedTaxes[$key] = $shippingTaxDetails;
                    }
                    $appliedTaxes[$key]['amount'] += floatval($creditMemo->getShippingTaxAmount());
                }
            } else {
                $creditMemoShipping['tax_percent'] = 0;
            }

            $salesOrderTaxDetails = $connection->fetchRow("SELECT * FROM `sales_order_tax` WHERE `order_id` = {$creditMemo->getOrderId()}");
            if ($salesOrderTaxDetails) {
                $adjustmentTaxPercent = floatval($salesOrderTaxDetails['percent'] ?? 0);
                $adjustmentTaxCode = $salesOrderTaxDetails['code'] ?? '';
                if ($creditMemo->getAdjustment() > 0 ) {
                    $adjustmentTaxAmount = (floatval($creditMemo->getAdjustment()) * $adjustmentTaxPercent) / (100 + $adjustmentTaxPercent);
                    $creditMemoData['adjustment_tax_amount'] = $adjustmentTaxAmount;
                    $creditMemoData['adjustment_tax_percent'] = $adjustmentTaxPercent;
                    $creditMemoData['adjustment_tax_code'] = $adjustmentTaxCode . " Refund";
                } else if ($creditMemo->getAdjustment() < 0) {
                    $adjustmentTaxAmount = (floatval($creditMemo->getAdjustment()) * $adjustmentTaxPercent) / (100 + $adjustmentTaxPercent);
                    $creditMemoData['adjustment_tax_amount'] = $adjustmentTaxAmount;
                    $creditMemoData['adjustment_tax_percent'] = $adjustmentTaxPercent;
                    $creditMemoData['adjustment_tax_code'] = $adjustmentTaxCode . " Refund fees";
                } else {
                    $creditMemoData['adjustment_tax_amount'] = 0;
                    $creditMemoData['adjustment_tax_percent'] = 0;
                    $creditMemoData['adjustment_tax_code'] = "";
                }
            } else {
                $creditMemoData['adjustment_tax_amount'] = 0;
                $creditMemoData['adjustment_tax_percent'] = 0;
                $creditMemoData['adjustment_tax_code'] = "";
            }

            //-- order
            $order = $this->getOrder($connection, $creditMemo->getOrderId());
            if ($order) {
                $creditMemoData = array_merge($creditMemoData, $order);
                $creditMemoShipping['method'] = $order['shipping_method'] ?? '';
                $creditMemoShipping['title'] = $order['shipping_description'] ?? '';
            }
            $creditMemoShipping['amount'] = floatval($creditMemo->getShippingAmount());
            $creditMemoShipping['amount_incl_tax'] = floatval($creditMemo->getShippingInclTax());
            $creditMemoShipping['tax_amount'] = floatval($creditMemo->getShippingTaxAmount());

            $creditMemoData['items'] = $creditMemoItems;
            $creditMemoData['shipping'] = [$creditMemoShipping];
            $creditMemoData['applied_taxes'] = array_values($appliedTaxes);
            return $creditMemoData;
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

    private function getShippingTax($connection, $orderId)
    {
        try {
            $taxDetails = $connection->fetchRow(
                "SELECT sot.code AS tax_code, sot.title AS tax_title, soti.tax_percent as tax_percent
                FROM sales_order_tax AS sot
                   INNER JOIN sales_order_tax_item AS soti ON sot.tax_id = soti.tax_id
                   WHERE sot.order_id = ? AND soti.taxable_item_type = 'shipping'",
                [$orderId]
            );
            if ($taxDetails) {
                $taxDetails['tax_percent'] = floatval($taxDetails['tax_percent']);
                return $taxDetails;
            }
            return null;
        } catch (\Exception $exception) {
            return null;
        }
    }

    private function getOrder($connection, $orderId)
    {
        try {
            $order = $connection->fetchRow(
                "SELECT so.increment_id as order_increment_id, so.shipping_description as shipping_description, so.shipping_method as shipping_method, so.state as order_state, so.status as order_status, soa.country_id as shipping_country_id,soa.vat_id as vat_id, sos.label AS order_status_label
                    FROM `sales_order` AS so
                    LEFT JOIN sales_order_address AS soa ON soa.entity_id = so.shipping_address_id
                    LEFT JOIN sales_order_status AS sos ON sos.status = so.status
                    WHERE so.entity_id = ?",
                [$orderId]
            );
            if ($order) {
                return $order;
            }
            return null;
        } catch (\Exception $exception) {
            return null;
        }
    }


    private function getComments($connection, $invoiceId)
    {
        try {
            $comments = $connection->fetchAll(
                "SELECT comment, created_at,is_customer_notified, is_visible_on_front  FROM sales_creditmemo_comment WHERE parent_id = ?",
                [$invoiceId]
            );
            return $comments ?: [];
        } catch (\Exception $exception) {
            return [];
        }
    }

    private function getType($connection, string $sku)
    {
        try {
            return $connection->fetchOne(
                "SELECT type_id FROM `catalog_product_entity` WHERE sku = '{$sku}'"
            );
        } catch (\Exception $exception) {
            return "";
        }
    }
}
