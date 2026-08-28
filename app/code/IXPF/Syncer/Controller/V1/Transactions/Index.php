<?php

declare(strict_types=1);

namespace IXPF\Syncer\Controller\V1\Transactions;

use IXPF\Syncer\Controller\AbstractController;
use IXPF\Syncer\Helper\TokenValidator;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\App\ResponseInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Sales\Model\ResourceModel\Order\Payment\Transaction\CollectionFactory as TransactionCollectionFactory;

class Index extends AbstractController
{
    public function __construct(
        Context                              $context,
        TokenValidator                       $tokenValidator,
        JsonFactory                          $resultFactory,
        protected TransactionCollectionFactory $transactionCollectionFactory,
        protected ResourceConnection           $resourceConnection
    ) {
        parent::__construct($context, $tokenValidator, $resultFactory);
    }

    public function execute(): Json|ResultInterface|ResponseInterface
    {
        try {
            //-- token validation check
            $this->tokenValidator();
            $connection = $this->resourceConnection->getConnection();

            if ($transactionId = $this->getRequest()->getParam('transaction_id')) {
                $transactionCollection = $this->transactionCollectionFactory->create();
                $transactionCollection->addFieldToFilter('transaction_id', $transactionId);
                $transaction = $transactionCollection->getFirstItem();
                return $this->jsonResponse($this->getTransaction($transaction, $connection), 200);
            }

            $page = $this->getRequest()->getParam('page', 1);
            $pageSize = $this->getRequest()->getParam('limit', 10);
            $paramFromId = $this->getRequest()->getParam('from_id');
            $paramToId = $this->getRequest()->getParam('to_id');
            $paramFromDate = $this->getRequest()->getParam('from_date');
            $paramToDate = $this->getRequest()->getParam('to_date');
            $paramLatest = $this->getRequest()->getParam('latest');
            $paramOrderId = $this->getRequest()->getParam('order_id');

            $transactionCollection = $this->transactionCollectionFactory->create();
            if ($paramOrderId) {
                $transactionCollection->addFieldToFilter('order_id', $paramOrderId);
            } elseif ($paramFromId > 0 && $paramToId > $paramFromId) {
                $transactionCollection->addFieldToFilter('transaction_id', ['gteq' => $paramFromId]);
                $transactionCollection->addFieldToFilter('transaction_id', ['lteq' => $paramToId]);
            } elseif ($paramFromDate && $paramToDate) {
                // Normalize date-only strings to include full day boundary
                $fromDate = (strlen($paramFromDate) === 10) ? $paramFromDate . ' 00:00:00' : $paramFromDate;
                $toDate   = (strlen($paramToDate)   === 10) ? $paramToDate   . ' 23:59:59' : $paramToDate;
                $transactionCollection->addFieldToFilter('created_at', ['gteq' => $fromDate]);
                $transactionCollection->addFieldToFilter('created_at', ['lteq' => $toDate]);
                $transactionCollection->setPageSize($pageSize);
                $transactionCollection->setCurPage($page);
            } elseif ($paramLatest) {
                $transactionCollection->addFieldToFilter('created_at', ['gteq' => $paramLatest]);
                $transactionCollection->setPageSize($pageSize);
                $transactionCollection->setCurPage($page);
            } else {
                $transactionCollection->setPageSize($pageSize);
                $transactionCollection->setCurPage($page);
            }
            $transactionCollection->setOrder('created_at', 'DESC');
            $totalCount = $transactionCollection->getSize();

            $items = [];
            foreach ($transactionCollection as $transaction) {
                $transactionData = $this->getTransaction($transaction, $connection);
                if ($transactionData) {
                    $items[] = $transactionData;
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

    private function getTransaction($transaction, $connection)
    {
        try {
            $transactionData = [
                'transaction_id' => intval($transaction->getTransactionId()),
                'parent_id' => $transaction->getParentId() ? intval($transaction->getParentId()) : null,
                'order_id' => intval($transaction->getOrderId()),
                'payment_id' => intval($transaction->getPaymentId()),
                'txn_id' => strval($transaction->getTxnId()),
                'parent_txn_id' => strval($transaction->getParentTxnId()),
                'txn_type' => strval($transaction->getTxnType()),
                'is_closed' => intval($transaction->getIsClosed()),
                'additional_information' => $this->decodeAdditionalInformation($transaction->getData('additional_information')),
                'created_at' => $transaction->getCreatedAt(),
                'child_transactions' => $this->getChildTransactions($connection, intval($transaction->getTransactionId())),
            ];

            //-- order
            $order = $this->getOrder($connection, $transaction->getOrderId());
            if ($order) {
                $transactionData['order_increment_id'] = $order['order_increment_id'];
                $transactionData['order_state'] = $order['order_state'];
                $transactionData['order_status'] = $order['order_status'];
                $transactionData['order_status_label'] = $order['order_status_label'];
                $transactionData['order_currency_code'] = $order['order_currency_code'];
                $transactionData['grand_total'] = floatval($order['grand_total']);
                $transactionData['order_date'] = $order['order_created_at'];
                $transactionData['customer_email'] = $order['customer_email'];
                $transactionData['customer_name'] = trim(($order['customer_firstname'] ?? '') . ' ' . ($order['customer_lastname'] ?? ''));
                $transactionData['is_pos_order'] = intval($order['is_pos_order']);
            }

            //-- payment
            $payment = $this->getPayment($connection, $transaction->getPaymentId());
            if ($payment) {
                $transactionData['payment_method'] = $payment['method'];
                $transactionData['payment_amount_ordered'] = floatval($payment['amount_ordered']);
                $transactionData['payment_amount_paid'] = floatval($payment['amount_paid']);
                $transactionData['payment_amount_refunded'] = floatval($payment['amount_refunded']);
                $transactionData['payment_last_trans_id'] = strval($payment['last_trans_id']);
                $transactionData['payment_cc_type'] = strval($payment['cc_type']);
                $transactionData['payment_cc_last4'] = strval($payment['cc_last_4']);
            }

            return $transactionData;
        } catch (\Exception $exception) {
            return [];
        }
    }

    private function getChildTransactions($connection, $parentId, $depth = 0)
    {
        if ($parentId <= 0 || $depth > 5) {
            return [];
        }
        try {
            $children = $connection->fetchAll(
                "SELECT transaction_id, parent_id, order_id, payment_id, txn_id, parent_txn_id,
                        txn_type, is_closed, additional_information, created_at
                    FROM sales_payment_transaction WHERE parent_id = ?",
                [$parentId]
            );
            return array_map(function ($child) use ($connection, $depth) {
                return [
                    'transaction_id' => intval($child['transaction_id']),
                    'parent_id' => intval($child['parent_id']),
                    'order_id' => intval($child['order_id']),
                    'payment_id' => intval($child['payment_id']),
                    'txn_id' => strval($child['txn_id']),
                    'parent_txn_id' => strval($child['parent_txn_id']),
                    'txn_type' => strval($child['txn_type']),
                    'is_closed' => intval($child['is_closed']),
                    'additional_information' => $this->decodeAdditionalInformation($child['additional_information']),
                    'created_at' => $child['created_at'],
                    'child_transactions' => $this->getChildTransactions($connection, intval($child['transaction_id']), $depth + 1),
                ];
            }, $children ?: []);
        } catch (\Exception $exception) {
            return [];
        }
    }

    private function decodeAdditionalInformation($raw)
    {
        if (empty($raw)) {
            return [];
        }
        if (is_array($raw)) {
            return $raw;
        }
        $data = json_decode((string)$raw, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            return $data;
        }
        $data = @unserialize((string)$raw, ['allowed_classes' => false]);
        return is_array($data) ? $data : [];
    }

    private function getOrder($connection, $orderId)
    {
        try {
            $order = $connection->fetchRow(
                "SELECT so.increment_id AS order_increment_id, so.state AS order_state, so.status AS order_status,
                        so.order_currency_code AS order_currency_code, so.grand_total AS grand_total,
                        so.created_at AS order_created_at, so.customer_email AS customer_email,
                        so.customer_firstname AS customer_firstname, so.customer_lastname AS customer_lastname,
                        so.is_pos_order AS is_pos_order, sos.label AS order_status_label
                    FROM `sales_order` AS so
                    LEFT JOIN sales_order_status AS sos ON sos.status = so.status
                    WHERE so.entity_id = ?",
                [$orderId]
            );
            return $order ?: null;
        } catch (\Exception $exception) {
            return null;
        }
    }

    private function getPayment($connection, $paymentId)
    {
        try {
            $payment = $connection->fetchRow(
                "SELECT method, amount_ordered, amount_paid, amount_refunded, last_trans_id, cc_type, cc_last_4
                    FROM sales_order_payment WHERE entity_id = ?",
                [$paymentId]
            );
            return $payment ?: null;
        } catch (\Exception $exception) {
            return null;
        }
    }
}
