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
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Sales\Api\ShipmentRepositoryInterface;
use Magento\Sales\Model\Order\Pdf\Shipment as ShipmentPdf;
use Magento\Sales\Model\ResourceModel\Order\Shipment\CollectionFactory as ShipmentCollectionFactory;

/**
 * Returns the shipment PDF — same document as the admin "Print" button on a shipment.
 * Accepts shipment_id (single shipment) or order_id (all shipments of the order, combined).
 */
class ShipmentPrint extends AbstractController implements
    HttpGetActionInterface,
    HttpPostActionInterface,
    CsrfAwareActionInterface
{
    public function __construct(
        Context                                        $context,
        TokenValidator                                 $tokenValidator,
        JsonFactory                                    $resultFactory,
        protected Config $config,
        protected readonly ShipmentRepositoryInterface $shipmentRepository,
        protected readonly ShipmentCollectionFactory   $shipmentCollectionFactory,
        protected readonly ShipmentPdf                 $shipmentPdf,
        protected readonly OrderActionLogger           $orderLogger,
        protected readonly ActivityLogger              $activityLogger,
    ) {
        parent::__construct($context, $tokenValidator, $resultFactory);
    }

    public function execute()
    {
        try {
            $this->tokenValidator();

            // accept params from query string or JSON body
            $shipmentId = (int) $this->getRequest()->getParam('shipment_id');
            $orderId    = (int) $this->getRequest()->getParam('order_id');

            if (!$shipmentId && !$orderId) {
                $payload = json_decode((string) $this->getRequest()->getContent(), true) ?: [];
                $shipmentId = (int) ($payload['shipment_id'] ?? 0);
                $orderId    = (int) ($payload['order_id'] ?? 0);
            }

            if (!$shipmentId && !$orderId) {
                throw new Exception('shipment_id or order_id is required');
            }

            if (!$this->config->isShipmentUpdateEnabled()) {
                throw new Exception('Shipment print is disabled in configuration');
            }

            $this->orderLogger->writeDaily(
                '[shipment_print] Received print request — '
                . ($shipmentId ? "Shipment ID: $shipmentId" : "Order ID: $orderId")
            );

            [$shipments, $fileName] = $shipmentId
                ? $this->loadByShipment($shipmentId)
                : $this->loadByOrder($orderId);

            $pdf = $this->shipmentPdf->getPdf($shipments);
            $pdfContent = $pdf->render();

            $this->orderLogger->writeDaily(
                "[shipment_print] PDF generated — file: $fileName, count: " . count($shipments)
            );

            $this->activityLogger->success(
                'shipment_print',
                'shipment',
                $shipmentId ?: $orderId,
                "PDF generated — file: $fileName, count: " . count($shipments)
            );

            return $this->jsonResponse([
                'success'      => true,
                'shipment_id'  => $shipmentId ?: null,
                'order_id'     => $orderId ?: null,
                'count'        => count($shipments),
                'file_name'    => $fileName,
                'file_content' => base64_encode($pdfContent),
                'mime_type'    => 'application/pdf',
            ]);
        } catch (Exception $exception) {
            $this->orderLogger->writeDaily('[shipment_print] Error printing shipment: ' . $exception->getMessage(), 'error');
            $this->activityLogger->error(
                'shipment_print',
                'shipment',
                ($shipmentId ?? null) ?: ($orderId ?? null),
                $exception->getMessage()
            );
            return $this->jsonResponse([
                'error' => $exception->getMessage(),
            ], 500);
        }
    }

    /**
     * @return array{0: array, 1: string}
     */
    private function loadByShipment(int $shipmentId): array
    {
        $shipment = $this->shipmentRepository->get($shipmentId);
        return [[$shipment], "shipment_{$shipment->getIncrementId()}.pdf"];
    }

    /**
     * @return array{0: array, 1: string}
     */
    private function loadByOrder(int $orderId): array
    {
        $collection = $this->shipmentCollectionFactory->create()
            ->addFieldToFilter('order_id', $orderId)
            ->setOrder('entity_id', 'ASC');

        $shipments = array_values($collection->getItems());

        if (!$shipments) {
            throw new Exception("No shipments found for order #$orderId");
        }

        return [$shipments, "shipments_order_{$orderId}.pdf"];
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
