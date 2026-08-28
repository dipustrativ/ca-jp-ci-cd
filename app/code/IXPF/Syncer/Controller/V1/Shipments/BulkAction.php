<?php
declare(strict_types=1);

namespace IXPF\Syncer\Controller\V1\Shipments;

use Exception;
use IXPF\Syncer\Controller\AbstractController;
use IXPF\Syncer\Helper\ActivityLogger;
use IXPF\Syncer\Helper\Config;
use IXPF\Syncer\Helper\Logger\OrderActionLogger;
use IXPF\Syncer\Helper\TokenValidator;
use IXPF\Syncer\Services\Shipment\BulkPrint;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\JsonFactory;

/**
 * Single endpoint for bulk shipment actions over selected shipment ids.
 *
 * POST /ixpfsyncer/V1/Shipment/BulkAction
 *
 * Body:
 *   {
 *     "action": "pdf_shipments | print_shipping_labels",
 *     "shipment_ids": [1, 2, 3]
 *   }
 *
 * Mirrors the admin shipment grid mass actions:
 *   - pdf_shipments          → "PDF Shipments" (packing-slip PDF)
 *   - print_shipping_labels  → "Print Shipping Labels" (combined carrier labels;
 *                              shipments without a generated label are reported
 *                              under "missing")
 *
 * Returns one combined PDF (base64) across all selected shipments.
 */
class BulkAction extends AbstractController implements HttpPostActionInterface, CsrfAwareActionInterface
{
    private const ACTION_PDF_SHIPMENTS         = 'pdf_shipments';
    private const ACTION_PRINT_SHIPPING_LABELS = 'print_shipping_labels';

    private const ACTIONS = [
        self::ACTION_PDF_SHIPMENTS,
        self::ACTION_PRINT_SHIPPING_LABELS,
    ];

    public function __construct(
        Context $context,
        TokenValidator $tokenValidator,
        JsonFactory $resultFactory,
        protected readonly Config $config,
        protected readonly BulkPrint $bulkPrint,
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

            if (!$this->config->isShipmentUpdateEnabled()) {
                throw new Exception('Shipment action is disabled in configuration');
            }

            $payload     = json_decode((string) $this->getRequest()->getContent(), true) ?: [];
            $action      = strtolower(trim((string) ($this->getRequest()->getParam('action') ?? $payload['action'] ?? '')));
            $shipmentIds = $this->resolveShipmentIds($payload);

            if ($action === '') {
                throw new Exception('action is required');
            }
            if (!in_array($action, self::ACTIONS, true)) {
                throw new Exception(
                    "Invalid action '$action'. Allowed: " . implode(', ', self::ACTIONS)
                );
            }
            if (!$shipmentIds) {
                throw new Exception('shipment_ids is required (non-empty array of shipment IDs)');
            }

            $this->orderLogger->writeDaily(
                "[shipment_bulk_action] action=$action shipment_ids=" . implode(',', $shipmentIds)
            );

            $data = $action === self::ACTION_PRINT_SHIPPING_LABELS
                ? $this->bulkPrint->printShippingLabels($shipmentIds)
                : $this->bulkPrint->printPackingSlips($shipmentIds);

            $message = "[$action] file: {$data['file_name']}, docs: " . ($data['count'] ?? 0)
                . (!empty($data['missing']) ? ', missing: ' . implode(',', $data['missing']) : '');
            $this->orderLogger->writeDaily("[shipment_bulk_action] $message");
            $this->activityLogger->success("shipment_bulk_action_$action", 'shipment', implode(',', $shipmentIds), $message);

            return $this->jsonResponse(['success' => true, 'action' => $action] + $data);
        } catch (Exception $exception) {
            $this->orderLogger->writeDaily('[shipment_bulk_action] Error: ' . $exception->getMessage(), 'error');
            $activityType = $action ? "shipment_bulk_action_$action" : 'shipment_bulk_action';
            $this->activityLogger->error($activityType, 'shipment', null, $exception->getMessage());
            return $this->jsonResponse(['success' => false, 'error' => $exception->getMessage()], 400);
        }
    }

    /**
     * Normalise + de-duplicate shipment IDs from query param or JSON body.
     *
     * @return int[]
     */
    private function resolveShipmentIds(array $payload): array
    {
        $raw = $this->getRequest()->getParam('shipment_ids') ?? $payload['shipment_ids'] ?? [];
        if (is_string($raw)) {
            $raw = array_filter(array_map('trim', explode(',', $raw)), 'strlen');
        }
        if (!is_array($raw)) {
            return [];
        }
        return array_values(array_unique(array_filter(array_map('intval', $raw))));
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
