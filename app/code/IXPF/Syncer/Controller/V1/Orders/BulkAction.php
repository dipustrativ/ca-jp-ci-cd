<?php
declare(strict_types=1);

namespace IXPF\Syncer\Controller\V1\Orders;

use Exception;
use IXPF\Syncer\Controller\AbstractController;
use IXPF\Syncer\Helper\ActivityLogger;
use IXPF\Syncer\Helper\Config;
use IXPF\Syncer\Helper\Logger\OrderActionLogger;
use IXPF\Syncer\Helper\TokenValidator;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Sales\Api\OrderManagementInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Pdf\Creditmemo as CreditmemoPdf;
use Magento\Sales\Model\Order\Pdf\Invoice as InvoicePdf;
use Magento\Sales\Model\Order\Pdf\Shipment as ShipmentPdf;
use Magento\Sales\Model\ResourceModel\Order\Creditmemo\CollectionFactory as CreditmemoCollectionFactory;
use Magento\Sales\Model\ResourceModel\Order\Invoice\CollectionFactory as InvoiceCollectionFactory;
use Magento\Sales\Model\ResourceModel\Order\Shipment\CollectionFactory as ShipmentCollectionFactory;

/**
 * Single endpoint for bulk order actions — core Magento actions only.
 *
 * POST /ixpfsyncer/V1/Orders/BulkAction
 *
 * Body:
 *   {
 *     "action": "cancel | hold | unhold
 *                | print_invoice | print_shipment | print_creditmemo",
 *     "order_ids": [1, 2, 3],
 *     "comment": "optional note",       // state actions only
 *     "visible_storefront": false       // state actions only
 *   }
 *
 * Return shape depends on action:
 *   - state actions (cancel/hold/unhold): per-order results + summary.
 *     One failing order does NOT stop the rest (partial success, HTTP 200).
 *   - print actions: one combined PDF (base64) across all selected orders.
 *
 * Extensibility: other modules add their own (non-core) actions by plugging
 * {@see self::resolveActionData()} with an after-plugin — it returns null for unknown
 * actions so a plugin can supply the response data. Keeps store-specific
 * business actions out of this module (e.g. CarpetAvenue ready_for_handling).
 */
class BulkAction extends AbstractController implements HttpPostActionInterface, CsrfAwareActionInterface
{
    private const ACTION_CANCEL          = 'cancel';
    private const ACTION_HOLD            = 'hold';
    private const ACTION_UNHOLD          = 'unhold';
    private const ACTION_PRINT_INVOICE   = 'print_invoice';
    private const ACTION_PRINT_SHIPMENT  = 'print_shipment';
    private const ACTION_PRINT_CREDITMEMO = 'print_creditmemo';

    private const STATE_ACTIONS = [
        self::ACTION_CANCEL,
        self::ACTION_HOLD,
        self::ACTION_UNHOLD,
    ];

    private const PRINT_ACTIONS = [
        self::ACTION_PRINT_INVOICE,
        self::ACTION_PRINT_SHIPMENT,
        self::ACTION_PRINT_CREDITMEMO,
    ];

    public function __construct(
        Context $context,
        TokenValidator $tokenValidator,
        JsonFactory $resultFactory,
        protected readonly Config $config,
        protected readonly OrderRepositoryInterface $orderRepository,
        protected readonly OrderManagementInterface $orderManagement,
        protected readonly InvoiceCollectionFactory $invoiceCollectionFactory,
        protected readonly ShipmentCollectionFactory $shipmentCollectionFactory,
        protected readonly CreditmemoCollectionFactory $creditmemoCollectionFactory,
        protected readonly InvoicePdf $invoicePdf,
        protected readonly ShipmentPdf $shipmentPdf,
        protected readonly CreditmemoPdf $creditmemoPdf,
        protected readonly OrderActionLogger $orderLogger,
        protected readonly ActivityLogger $activityLogger,
    ) {
        parent::__construct($context, $tokenValidator, $resultFactory);
    }

    public function execute()
    {
        $action = null;
        try {
            $this->tokenValidator();

            $payload  = json_decode((string) $this->getRequest()->getContent(), true) ?: [];
            $action   = strtolower(trim((string) ($this->getRequest()->getParam('action') ?? $payload['action'] ?? '')));
            $orderIds = $this->resolveOrderIds($payload);

            if ($action === '') {
                throw new Exception('action is required');
            }
            if (!$orderIds) {
                throw new Exception('order_ids is required (non-empty array of order IDs)');
            }

            $this->assertActionEnabled($action);

            $this->orderLogger->writeDaily(
                "[bulk_action] action=$action order_ids=" . implode(',', $orderIds)
            );

            $data = $this->resolveActionData($action, $orderIds, $payload);
            if ($data === null) {
                throw new Exception(
                    "Invalid action '$action'. Allowed (core): "
                    . implode(', ', array_merge(self::STATE_ACTIONS, self::PRINT_ACTIONS))
                );
            }

            // Single place to log the outcome — file + ixpf_order_api_activity —
            // for every action, core and plugin-supplied (e.g. ready_for_handling).
            $this->logOutcome($action, $orderIds, $data);

            return $this->jsonResponse(['success' => true, 'action' => $action] + $data);
        } catch (Exception $exception) {
            $this->orderLogger->writeDaily('[bulk_action] Error: ' . $exception->getMessage(), 'error');
            $activityType = $action ? "bulk_action_$action" : 'bulk_action';
            $this->activityLogger->error($activityType, 'order', null, $exception->getMessage());
            return $this->jsonResponse(['success' => false, 'error' => $exception->getMessage()], 400);
        }
    }

    /**
     * Resolve an action to its response data.
     *
     * Returns null for an unknown action so other modules can extend this
     * endpoint via an after-plugin (the plugin returns the data for actions it
     * owns). Core Magento actions are handled here; store-specific actions are
     * NOT — they belong in their own module.
     *
     * Note: named resolveActionData (not dispatch) to avoid clashing with
     * Magento\Framework\App\Action\Action::dispatch().
     *
     * @param int[] $orderIds
     * @return array|null  null = action not handled here
     * @throws Exception
     */
    public function resolveActionData(string $action, array $orderIds, array $payload): ?array
    {
        return match ($action) {
            self::ACTION_CANCEL,
            self::ACTION_HOLD,
            self::ACTION_UNHOLD          => $this->runState($action, $orderIds, $payload),
            self::ACTION_PRINT_INVOICE   => $this->printInvoices($orderIds),
            self::ACTION_PRINT_SHIPMENT  => $this->printShipments($orderIds),
            self::ACTION_PRINT_CREDITMEMO => $this->printCreditmemos($orderIds),
            default => null,
        };
    }

    /**
     * Log the resolved outcome to both the daily file log and the
     * ixpf_order_api_activity table — one entry per order for state/plugin
     * actions, one entry for print actions.
     *
     * @param int[] $orderIds
     */
    private function logOutcome(string $action, array $orderIds, array $data): void
    {
        // Print actions — no per-order results, just the generated file.
        if (isset($data['file_name'])) {
            $message = "[$action] file: {$data['file_name']}, docs: "
                . ($data['count'] ?? 0) . ', orders: ' . ($data['order_count'] ?? 0)
                . (!empty($data['missing']) ? ', missing: ' . implode(',', $data['missing']) : '');
            $this->orderLogger->writeDaily("[bulk_action] $message");
            $this->activityLogger->success("bulk_action_$action", 'order', implode(',', $orderIds), $message);
            return;
        }

        // State / plugin actions — per-order results + summary.
        foreach ($data['results'] ?? [] as $result) {
            $reference = $result['increment_id'] ?? $result['order_id'] ?? null;
            if (!empty($result['success'])) {
                $detail = $result['message']
                    ?? "Order #$reference $action — status: " . ($result['status'] ?? '');
                $this->orderLogger->writeDaily("[bulk_action] $detail");
                $this->activityLogger->success("bulk_action_$action", 'order', $reference, $detail);
            } else {
                $detail = $result['error'] ?? 'failed';
                $this->orderLogger->writeDaily(
                    "[bulk_action] $action failed for Order #" . ($result['order_id'] ?? '?') . ": $detail",
                    'error'
                );
                $this->activityLogger->error("bulk_action_$action", 'order', $result['order_id'] ?? null, $detail);
            }
        }

        if (!empty($data['summary'])) {
            $summary = $data['summary'];
            $this->orderLogger->writeDaily(
                "[bulk_action] $action done — total: {$summary['total']}, "
                . "success: {$summary['success']}, failed: {$summary['failed']}"
            );
        }
    }

    /**
     * Normalise + de-duplicate order IDs from query param or JSON body.
     *
     * @return int[]
     */
    private function resolveOrderIds(array $payload): array
    {
        $raw = $this->getRequest()->getParam('order_ids') ?? $payload['order_ids'] ?? [];
        if (is_string($raw)) {
            $raw = array_filter(array_map('trim', explode(',', $raw)), 'strlen');
        }
        if (!is_array($raw)) {
            return [];
        }
        $ids = array_values(array_unique(array_filter(array_map('intval', $raw))));
        return $ids;
    }

    /**
     * @throws Exception
     */
    private function assertActionEnabled(string $action): void
    {
        $enabled = match ($action) {
            self::ACTION_CANCEL,
            self::ACTION_HOLD,
            self::ACTION_UNHOLD          => $this->config->isOrderUpdateEnabled(),
            self::ACTION_PRINT_INVOICE   => $this->config->isInvoiceUpdateEnabled(),
            self::ACTION_PRINT_SHIPMENT  => $this->config->isShipmentUpdateEnabled(),
            self::ACTION_PRINT_CREDITMEMO => $this->config->isCreditMemoUpdateEnabled(),
            default => true, // unknown/plugin-supplied actions gate themselves
        };
        if (!$enabled) {
            throw new Exception("Action '$action' is disabled in configuration");
        }
    }

    /**
     * Run a state action over every order. Per-order failures are captured;
     * the loop never aborts on a single bad order.
     *
     * @param int[] $orderIds
     * @return array{summary: array, results: array}
     */
    private function runState(string $action, array $orderIds, array $payload): array
    {
        $results = [];
        $ok = 0;

        foreach ($orderIds as $id) {
            try {
                $results[] = $this->applyStateAction($action, $id, $payload) + [
                    'order_id' => $id,
                    'success'  => true,
                ];
                $ok++;
            } catch (Exception $exception) {
                // Logged centrally in logOutcome() from the result set.
                $results[] = [
                    'order_id' => $id,
                    'success'  => false,
                    'error'    => $exception->getMessage(),
                ];
            }
        }

        return [
            'summary' => [
                'total'   => count($orderIds),
                'success' => $ok,
                'failed'  => count($orderIds) - $ok,
            ],
            'results' => $results,
        ];
    }

    /**
     * @return array{increment_id: string, state: string, status: string}
     * @throws Exception
     */
    private function applyStateAction(string $action, int $id, array $payload): array
    {
        $order = $this->loadOrder($id);
        $comment = trim((string) ($payload['comment'] ?? ''));
        $visible = !empty($payload['visible_storefront']);

        switch ($action) {
            case self::ACTION_CANCEL:
                if (!$order->canCancel()) {
                    throw new Exception(
                        "Order #{$order->getIncrementId()} cannot be cancelled "
                        . "(state: {$order->getState()}, status: {$order->getStatus()})"
                    );
                }
                $order->cancel();
                $order->addCommentToStatusHistory(
                    'Order canceled by IXPF API' . ($comment !== '' ? ' — ' . $comment : ''),
                    Order::STATE_CANCELED,
                    $visible
                );
                $this->orderRepository->save($order);
                break;

            case self::ACTION_HOLD:
                if (!$order->canHold()) {
                    throw new Exception(
                        "Order #{$order->getIncrementId()} cannot be held "
                        . "(state: {$order->getState()}, status: {$order->getStatus()})"
                    );
                }
                $this->orderManagement->hold($id);
                $order = $this->loadOrder($id);
                break;

            case self::ACTION_UNHOLD:
                if (!$order->canUnhold()) {
                    throw new Exception(
                        "Order #{$order->getIncrementId()} is not on hold "
                        . "(state: {$order->getState()}, status: {$order->getStatus()})"
                    );
                }
                $this->orderManagement->unHold($id);
                $order = $this->loadOrder($id);
                break;
        }

        return [
            'increment_id' => $order->getIncrementId(),
            'state'        => $order->getState(),
            'status'       => $order->getStatus(),
        ];
    }

    /**
     * @return Order
     * @throws Exception
     */
    private function loadOrder(int $id): Order
    {
        try {
            /** @var Order $order */
            $order = $this->orderRepository->get($id);
            return $order;
        } catch (NoSuchEntityException) {
            throw new Exception("Order #$id not found");
        }
    }

    /**
     * @param int[] $orderIds
     * @return array
     * @throws Exception
     */
    private function printInvoices(array $orderIds): array
    {
        $collection = $this->invoiceCollectionFactory->create()
            ->addFieldToFilter('order_id', ['in' => $orderIds])
            ->setOrder('entity_id', 'ASC');
        $docs = array_values($collection->getItems());
        if (!$docs) {
            throw new Exception('No invoices found for the selected orders');
        }
        return $this->renderPdf($this->invoicePdf->getPdf($docs)->render(), 'invoices_bulk.pdf', $docs, $orderIds);
    }

    /**
     * @param int[] $orderIds
     * @return array
     * @throws Exception
     */
    private function printShipments(array $orderIds): array
    {
        $collection = $this->shipmentCollectionFactory->create()
            ->addFieldToFilter('order_id', ['in' => $orderIds])
            ->setOrder('entity_id', 'ASC');
        $docs = array_values($collection->getItems());
        if (!$docs) {
            throw new Exception('No shipments found for the selected orders');
        }
        return $this->renderPdf($this->shipmentPdf->getPdf($docs)->render(), 'shipments_bulk.pdf', $docs, $orderIds);
    }

    /**
     * @param int[] $orderIds
     * @return array
     * @throws Exception
     */
    private function printCreditmemos(array $orderIds): array
    {
        $collection = $this->creditmemoCollectionFactory->create()
            ->addFieldToFilter('order_id', ['in' => $orderIds])
            ->setOrder('entity_id', 'ASC');
        $docs = array_values($collection->getItems());
        if (!$docs) {
            throw new Exception('No credit memos found for the selected orders');
        }
        return $this->renderPdf($this->creditmemoPdf->getPdf($docs)->render(), 'creditmemos_bulk.pdf', $docs, $orderIds);
    }

    /**
     * Wrap rendered PDF into the response payload. Logging is handled centrally
     * in logOutcome().
     *
     * @param object[] $docs
     * @param int[]    $orderIds
     */
    private function renderPdf(string $pdfContent, string $fileName, array $docs, array $orderIds): array
    {
        $coveredOrderIds = array_values(array_unique(array_map(
            static fn ($doc) => (int) $doc->getOrderId(),
            $docs
        )));
        $missing = array_values(array_diff($orderIds, $coveredOrderIds));

        return [
            'count'        => count($docs),
            'order_count'  => count($coveredOrderIds),
            'missing'      => $missing,
            'file_name'    => $fileName,
            'file_content' => base64_encode($pdfContent),
            'mime_type'    => 'application/pdf',
        ];
    }

    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        return null;
    }

    public function validateForCsrf(RequestInterface $request): ?bool
    {
        return true;
    }
}
