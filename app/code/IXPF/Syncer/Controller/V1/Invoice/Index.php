<?php

namespace IXPF\Syncer\Controller\V1\Invoice;

use IXPF\Syncer\Controller\AbstractController;
use IXPF\Syncer\Helper\TokenValidator;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Sales\Model\ResourceModel\Order\Invoice\CollectionFactory as InvoiceCollectionFactory;

class Index extends AbstractController
{

    public function __construct(
        Context                              $context,
        TokenValidator                       $tokenValidator,
        JsonFactory                          $resultFactory,
        protected InvoiceCollectionFactory   $invoiceCollectionFactory,
        protected ResourceConnection         $resourceConnection,
        protected ProductRepositoryInterface $productRepository
    )
    {
        parent::__construct($context, $tokenValidator, $resultFactory);
    }

    public function execute()
    {
        try {
            //-- token validation check
            $this->tokenValidator();
            $connection = $this->resourceConnection->getConnection();

            if ($invoiceId = $this->getRequest()->getParam('invoice_id')) {
                $invoiceCollection = $this->invoiceCollectionFactory->create();
                $invoiceCollection->addFieldToFilter('entity_id', $invoiceId);
                $invoice = $invoiceCollection->getFirstItem();
                return $this->jsonResponse($this->getInvoice($invoice, $connection), 200);
            }

            $page = $this->getRequest()->getParam('page', 1);
            $pageSize = $this->getRequest()->getParam('limit', 10);
            $paramFromId = $this->getRequest()->getParam('from_id');
            $paramToId = $this->getRequest()->getParam('to_id');
            $paramFromDate = $this->getRequest()->getParam('from_date');
            $paramToDate = $this->getRequest()->getParam('to_date');
            $paramLatestInvoice = $this->getRequest()->getParam('latest');

            $invoiceCollection = $this->invoiceCollectionFactory->create();
            if ($paramFromId > 0 && $paramToId > $paramFromId) {
                $invoiceCollection->addFieldToFilter('entity_id', ['gteq' => $paramFromId]);
                $invoiceCollection->addFieldToFilter('entity_id', ['lteq' => $paramToId]);
            } elseif ($paramFromDate && $paramToDate) {
                // Normalize date-only strings to include full day boundary
                $fromDate = (strlen($paramFromDate) === 10) ? $paramFromDate . ' 00:00:00' : $paramFromDate;
                $toDate   = (strlen($paramToDate)   === 10) ? $paramToDate   . ' 23:59:59' : $paramToDate;
                $invoiceCollection->addFieldToFilter('updated_at', ['gteq' => $fromDate]);
                $invoiceCollection->addFieldToFilter('updated_at', ['lteq' => $toDate]);
                $invoiceCollection->setPageSize($pageSize);
                $invoiceCollection->setCurPage($page);
            } elseif ($paramLatestInvoice) {
                $invoiceCollection->addFieldToFilter('updated_at', ['gteq' => $paramLatestInvoice]);
                $invoiceCollection->setPageSize($pageSize);
                $invoiceCollection->setCurPage($page);
            } else {
                $invoiceCollection->setPageSize($pageSize);
                $invoiceCollection->setCurPage($page);
            }

            $invoiceCollection->setOrder('created_at', 'DESC');
            $totalCount = $invoiceCollection->getSize();

            $items = [];
            foreach ($invoiceCollection as $invoice) {
                $invoiceData = $this->getInvoice($invoice, $connection);
                if ($invoiceData) {
                    $items[] = $invoiceData;
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

    private function getInvoice($invoice, $connection)
    {
        try {
            $invoiceData = [
                'entity_id' => intval($invoice->getEntityId()),
                'increment_id' => strval($invoice->getIncrementId()),
                'store_id' => intval($invoice->getStoreId()),
                'order_id' => intval($invoice->getOrderId()),
                'state' => intval($invoice->getState()),
                'order_currency_code' => $invoice->getOrderCurrencyCode(),
                'base_to_global_rate' => doubleval($invoice->getBaseToGlobalRate()),
                'store_to_eur_rate' => doubleval($invoice->getStoreToEurRate()),
                'discount_tax_compensation_amount' => floatval($invoice->getDiscountTaxCompensationAmount()),
                'shipping_discount_tax_compensation_amount' => floatval($invoice->getShippingDiscountTaxCompensationAmount()),
                'discount_amount' => floatval($invoice->getDiscountAmount()),
                'shipping_amount' => floatval($invoice->getShippingAmount()),
                'shipping_tax_amount' => floatval($invoice->getShippingTaxAmount()),
                'shipping_incl_tax' => floatval($invoice->getShippingInclTax()),
                'tax_amount' => floatval($invoice->getTaxAmount()),
                'subtotal' => floatval($invoice->getSubtotal()),
                'subtotal_incl_tax' => floatval($invoice->getSubtotalInclTax()),
                'grand_total' => floatval($invoice->getGrandTotal()),
                'order_date' => $invoice->getOrder()->getCreatedAt(),
                'coupon_code' => $invoice->getOrder()->getCouponCode(),
                'is_pos_order' => intval($invoice->getOrder()->getIsPosOrder()),
                'comments' => $this->getComments($connection, $invoice->getEntityId()),
                'created_at' => $invoice->getCreatedAt(),
                'updated_at' => $invoice->getUpdatedAt(),
            ];
            $invoiceItems = [];
            $invoiceShipping = [];
            $appliedTaxes = [];
            //-- invoice items
            foreach ($invoice->getItems() as $item) {
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

                $invoiceItems[] = $itemData;
            }

            //-- shipping tax
            $shippingTaxDetails = null;
            if (floatval($invoice->getShippingTaxAmount()) > 0) {
                $shippingTaxDetails = $this->getShippingTax($connection, $invoice->getOrderId());
                if ($shippingTaxDetails) {
                    $percent = floatval($shippingTaxDetails['tax_percent']);
                    $invoiceShipping['tax_percent'] = $percent;
                    $key = (string)$percent;
                    if (!isset($appliedTaxes[$key])) {
                        $shippingTaxDetails['amount'] = 0;
                        $appliedTaxes[$key] = $shippingTaxDetails;
                    }
                    $appliedTaxes[$key]['amount'] += floatval($invoice->getShippingTaxAmount());
                }
            } else {
                $invoiceShipping['tax_percent'] = 0;
            }

            //-- order
            $order = $this->getOrder($connection, $invoice->getOrderId());
            if ($order) {
                $invoiceData = array_merge($invoiceData, $order);
                $invoiceShipping['method'] = $order['shipping_method'] ?? '';
                $invoiceShipping['title'] = $order['shipping_description'] ?? '';
            }
            $invoiceShipping['amount'] = floatval($invoice->getShippingAmount());
            $invoiceShipping['amount_incl_tax'] = floatval($invoice->getShippingInclTax());
            $invoiceShipping['tax_amount'] = floatval($invoice->getShippingTaxAmount());

            $invoiceData['items'] = $invoiceItems;
            $invoiceData['shipping'] = [$invoiceShipping];
            $invoiceData['applied_taxes'] = array_values($appliedTaxes);
            return $invoiceData;
        } catch (\Exception $exception) {
            return [];
        }
    }

    private function getProductInformation($productId)
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
                "SELECT comment, created_at,is_customer_notified, is_visible_on_front  FROM sales_invoice_comment WHERE parent_id = ?",
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