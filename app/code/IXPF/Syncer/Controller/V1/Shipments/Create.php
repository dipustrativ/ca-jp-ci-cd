<?php
declare(strict_types=1);

namespace IXPF\Syncer\Controller\V1\Shipments;

use Exception;
use IXPF\Syncer\Controller\AbstractController;
use IXPF\Syncer\Helper\ActivityLogger;
use IXPF\Syncer\Helper\Config;
use IXPF\Syncer\Helper\Logger\OrderActionLogger;
use IXPF\Syncer\Helper\TokenValidator;
use IXPF\Syncer\Services\Order\CreateShipment as CreateShipmentService;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\JsonFactory;

class Create extends AbstractController implements HttpPostActionInterface, CsrfAwareActionInterface
{
    public function __construct(
        Context $context,
        TokenValidator $tokenValidator,
        JsonFactory $resultFactory,
        protected readonly CreateShipmentService $createShipment,
        protected readonly Config $config,
        protected readonly OrderActionLogger $orderLogger,
        protected readonly ActivityLogger $activityLogger
    ) {
        parent::__construct($context, $tokenValidator, $resultFactory);
    }

    public function execute()
    {
        try {
            $this->tokenValidator();

            $id = $this->getRequest()->getParam('id');
            if (!$id) {
                throw new Exception('Order ID is required');
            }

            if (!$this->config->isShipmentUpdateEnabled()) {
                throw new Exception("Shipment is disabled configuration");
            }

            $requestData = $this->getRequest()->getContent();
            $payload = json_decode($requestData, true) ?? [];

            $this->orderLogger->writeDaily("[shipment_create] Received create shipment request for Order ID: $id");
            $this->orderLogger->writeDaily('[shipment_create] Payload: ' . json_encode($payload));

            $result = $this->createShipment->execute((int) $id, $payload);

            $created = array_map(
                fn(array $shipment) => $shipment['shipment_increment_id']
                    . ($shipment['source_code'] ? " ({$shipment['source_code']})" : ''),
                $result['shipments']
            );
            $this->orderLogger->writeDaily(
                "[shipment_create] Shipment(s) created for Order ID: $id — " . implode(', ', $created)
            );

            foreach ($result['shipments'] as $shipment) {
                $this->activityLogger->success(
                    'shipment_create',
                    'shipment',
                    $shipment['shipment_increment_id'],
                    "Shipment created for order #{$result['increment_id']}"
                    . ($shipment['source_code'] ? " from source {$shipment['source_code']}" : '')
                );
            }

            $count = count($result['shipments']);
            return $this->jsonResponse([
                'success' => true,
                'message' => $count > 1
                    ? "$count shipments created for order #{$result['increment_id']} (one per source)"
                    : "Shipment created for order #{$result['increment_id']}",
                'data'    => $result,
            ]);
        } catch (Exception $exception) {
            $this->orderLogger->writeDaily('[shipment_create] Error creating shipment: ' . $exception->getMessage(), 'error');
            $this->activityLogger->error('shipment_create', 'shipment', $id ?? null, $exception->getMessage());
            return $this->jsonResponse(['error' => $exception->getMessage()], 500);
        }
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
