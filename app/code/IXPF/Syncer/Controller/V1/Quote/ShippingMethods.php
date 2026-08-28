<?php
declare(strict_types=1);

namespace IXPF\Syncer\Controller\V1\Quote;

use Exception;
use IXPF\Syncer\Controller\AbstractController;
use IXPF\Syncer\Helper\Logger\QuoteLogger;
use IXPF\Syncer\Helper\TokenValidator;
use IXPF\Syncer\Services\Quote\ShippingMethods as ShippingMethodsService;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\Result\JsonFactory;

class ShippingMethods extends AbstractController implements HttpGetActionInterface
{
    public function __construct(
        Context $context,
        TokenValidator $tokenValidator,
        JsonFactory $resultFactory,
        protected readonly ShippingMethodsService $shippingMethods,
        protected readonly QuoteLogger $logger
    ) {
        parent::__construct($context, $tokenValidator, $resultFactory);
    }

    public function execute()
    {
        try {
            $this->tokenValidator();

            $quoteId = (int)$this->getRequest()->getParam('quote_id');
            if ($quoteId <= 0) {
                throw new Exception('quote_id is required');
            }

            $result = $this->shippingMethods->process($quoteId);

            return $this->jsonResponse(['success' => true, 'data' => $result]);
        } catch (Exception $e) {
            $this->logger->writeDaily('[quote_shipping_methods] error: ' . $e->getMessage(), 'error');
            return $this->jsonResponse(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }
}
