<?php
declare(strict_types=1);

namespace IXPF\CarpetAvenueJP\Controller\V1\Orders;

use CarpetAvenue\Proforma\Model\ProformaRepository;
use Exception;
use IXPF\Syncer\Controller\AbstractController;
use IXPF\Syncer\Helper\ActivityLogger;
use IXPF\Syncer\Helper\Logger\OrderActionLogger;
use IXPF\Syncer\Helper\TokenValidator;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;

class ProformaGenerate extends AbstractController implements HttpPostActionInterface, CsrfAwareActionInterface
{
    public function __construct(
        Context                                    $context,
        TokenValidator                             $tokenValidator,
        JsonFactory                                $resultFactory,
        protected readonly OrderRepositoryInterface $orderRepository,
        protected readonly ProformaRepository       $proformaRepository,
        protected readonly OrderActionLogger        $orderLogger,
        protected readonly ActivityLogger           $activityLogger
    ) {
        parent::__construct($context, $tokenValidator, $resultFactory);
    }

    public function execute()
    {
        try {
            $this->tokenValidator();

            $rawContent = $this->getRequest()->getContent();
            $payload = json_decode($rawContent, true);

            if (empty($payload['order_id'])) {
                throw new Exception('order_id is required');
            }

            $orderId = (int) $payload['order_id'];

            $this->orderLogger->writeDaily("[proforma_generate] Received proforma generate request for Order ID: $orderId");

            $order = $this->orderRepository->get($orderId);

            if (!in_array($order->getState(), [Order::STATE_NEW, Order::STATE_PROCESSING], true)) {
                throw new Exception(
                    "Order #{$order->getIncrementId()} proforma can only be created in the 'new' or 'processing' state. "
                    . "Current state: {$order->getState()}, status: {$order->getStatus()}"
                );
            }

            $proforma = $this->proformaRepository->create($order);
            $mpdf = $this->proformaRepository->generatePdf(
                $proforma,
                $order->getStoreId()
            );

            $pdfContent = $mpdf->Output('', 'S');
            $incrementId = $order->getIncrementId();

            $this->orderLogger->writeDaily(
                "[proforma_generate] Proforma {$proforma->getIncrementId()} generated for Order #$incrementId — file: proforma_{$incrementId}.pdf"
            );

            $this->activityLogger->success(
                'proforma_generate',
                'order',
                $incrementId,
                "Proforma {$proforma->getIncrementId()} generated — file: proforma_{$incrementId}.pdf"
            );

            return $this->jsonResponse([
                'success'      => true,
                'order_id'     => $orderId,
                'increment_id' => $incrementId,
                'file_name'    => "proforma_{$incrementId}.pdf",
                'file_content' => base64_encode($pdfContent),
                'mime_type'    => 'application/pdf',
            ]);
        } catch (Exception $exception) {
            $this->orderLogger->writeDaily('[proforma_generate] Error generating proforma: ' . $exception->getMessage(), 'error');
            $this->activityLogger->error('proforma_generate', 'order', $orderId ?? null, $exception->getMessage());
            return $this->jsonResponse([
                'error' => $exception->getMessage(),
            ], 500);
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
