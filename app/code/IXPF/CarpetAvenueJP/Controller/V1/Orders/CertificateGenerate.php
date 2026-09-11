<?php
declare(strict_types=1);

namespace IXPF\CarpetAvenueJP\Controller\V1\Orders;

use Evalent\ProductCertificate\Helper\Certificate;
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

class CertificateGenerate extends AbstractController implements HttpPostActionInterface, CsrfAwareActionInterface
{
    public function __construct(
        Context                                    $context,
        TokenValidator                             $tokenValidator,
        JsonFactory                                $resultFactory,
        protected readonly OrderRepositoryInterface $orderRepository,
        protected readonly Certificate              $certificate,
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

            $this->orderLogger->writeDaily("[certificate_generate] Received certificate generate request for Order ID: $orderId");

            $order = $this->orderRepository->get($orderId);
            $result = $this->certificate->create($order, true);
            if ($result['pdf']) {
                $pdfContent = $result['pdf']->Output('', 'S');
                $incrementId = $order->getIncrementId();

                $this->orderLogger->writeDaily(
                    "[certificate_generate] Certificate generated for Order #$incrementId — file: certificate_{$incrementId}.pdf"
                );

                $this->activityLogger->success(
                    'certificate_generate',
                    'order',
                    $incrementId,
                    "Certificate generated — file: certificate_{$incrementId}.pdf"
                );

                return $this->jsonResponse([
                    'success'       => true,
                    'order_id'      => $orderId,
                    'increment_id'  => $incrementId,
                    'file_name'     => "certificate_{$incrementId}.pdf",
                    'file_content'  => base64_encode($pdfContent),
                    'mime_type'     => 'application/pdf',
                ]);
            } else {
                throw new Exception('Failed to generate certificate PDF');
            }

        } catch (Exception $exception) {
            $this->orderLogger->writeDaily('[certificate_generate] Error generating certificate: ' . $exception->getMessage(), 'error');
            $this->activityLogger->error('certificate_generate', 'order', $orderId ?? null, $exception->getMessage());
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
