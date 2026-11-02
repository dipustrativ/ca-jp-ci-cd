<?php
declare(strict_types=1);

namespace IXPF\Syncer\Controller\V1\Quote;

use Exception;
use IXPF\Syncer\Controller\AbstractController;
use IXPF\Syncer\Helper\Config;
use IXPF\Syncer\Helper\Logger\QuoteLogger;
use IXPF\Syncer\Helper\TokenValidator;
use IXPF\Syncer\Services\Quote\SetShippingMethod as SetShippingMethodService;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\JsonFactory;

class SetShippingMethod extends AbstractController implements HttpPostActionInterface, CsrfAwareActionInterface
{
    public function __construct(
        Context $context,
        TokenValidator $tokenValidator,
        JsonFactory $resultFactory,
        protected readonly SetShippingMethodService $setShippingMethod,
        protected readonly Config $config,
        protected readonly QuoteLogger $logger
    ) {
        parent::__construct($context, $tokenValidator, $resultFactory);
    }

    public function execute()
    {
        try {
            $this->tokenValidator();

            if (!$this->config->isOrderCreateEnabled()) {
                throw new Exception('Order create is disabled in config');
            }

            $quoteId = (int)$this->getRequest()->getParam('quote_id');
            if ($quoteId <= 0) {
                throw new Exception('quote_id is required');
            }

            $raw     = $this->getRequest()->getContent();
            $payload = json_decode($raw, true) ?: [];
            $method  = (string)($payload['shipping_method'] ?? '');

            $this->logger->writeDaily("[quote_shipping] quote #$quoteId payload: $raw");

            $result = $this->setShippingMethod->process($quoteId, $method);

            return $this->jsonResponse(['success' => true, 'data' => $result]);
        } catch (Exception $e) {
            $this->logger->writeDaily('[quote_shipping] error: ' . $e->getMessage(), 'error');
            return $this->jsonResponse(['success' => false, 'error' => $e->getMessage()], 500);
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
