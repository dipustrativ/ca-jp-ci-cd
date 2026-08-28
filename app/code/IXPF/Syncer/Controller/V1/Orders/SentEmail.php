<?php

namespace IXPF\Syncer\Controller\V1\Orders;

use Exception;
use IXPF\Syncer\Controller\AbstractController;
use IXPF\Syncer\Helper\ActivityLogger;
use IXPF\Syncer\Helper\Config;
use IXPF\Syncer\Helper\Logger\OrderActionLogger;
use IXPF\Syncer\Helper\TokenValidator;
use IXPF\Syncer\Services\Order\Email;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Controller\Result\JsonFactory;

class SentEmail extends AbstractController
{
    public function __construct(
        Context $context,
        TokenValidator $tokenValidator,
        JsonFactory $resultFactory,
        protected Config $config,
        protected Email $email,
        protected readonly OrderActionLogger $orderLogger,
        protected readonly ActivityLogger $activityLogger
    )
    {
        parent::__construct($context, $tokenValidator, $resultFactory);
    }

    public function execute()
    {
        try {
            //-- token validation check
            $this->tokenValidator();
            $id = $this->getRequest()->getParam('id');
            if (!$id) {
                throw new Exception('Order ID is required');
            }
            $type = $this->getRequest()->getParam('type', 'order');
            $payload = ['type' => $type];

            if ($type == "order" && !$this->config->isOrderUpdateEnabled()) {
                throw new Exception('Order is disabled in configuration');
            }
            if ($type == "shipment" && !$this->config->isShipmentUpdateEnabled()) {
                throw new Exception('Shipment is disabled in configuration');
            }
            if ($type == "creditmemo" && !$this->config->isCreditMemoUpdateEnabled()) {
                throw new Exception('Credit Memo is disabled in configuration');
            }
            if ($type == "invoice" && !$this->config->isInvoiceUpdateEnabled()) {
                throw new Exception('Invoice is disabled in configuration');
            }

            $this->orderLogger->writeDaily("[sent_email] Received send email request for Order ID: $id — type: $type");

            $result = $this->email->send((int)$id, $payload);

            $this->orderLogger->writeDaily(
                "[sent_email] Email sent for Order ID: $id — " . json_encode($result)
            );

            $this->activityLogger->success('sent_email', 'order', $id, "Email sent for Order ID: $id — type: $type");

            return $this->jsonResponse([
                'success' => true,
                'data' => $result
            ]);

        } catch (Exception $e) {
            $this->orderLogger->writeDaily('[sent_email] Error sending order email: ' . $e->getMessage(), 'error');
            $this->activityLogger->error('sent_email', 'order', $id ?? null, $e->getMessage());
            return $this->jsonResponse(['error' => $e->getMessage()], 500);
        }
    }
}