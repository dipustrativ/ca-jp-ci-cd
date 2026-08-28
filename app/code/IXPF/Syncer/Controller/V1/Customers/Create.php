<?php
declare(strict_types=1);

namespace IXPF\Syncer\Controller\V1\Customers;

use Exception;
use IXPF\Syncer\Controller\AbstractController;
use IXPF\Syncer\Helper\ActivityLogger;
use IXPF\Syncer\Helper\Config;
use IXPF\Syncer\Helper\Logger\CustomerLogger;
use IXPF\Syncer\Helper\TokenValidator;
use IXPF\Syncer\Services\Customer\CreateCustomer;
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
        protected readonly CreateCustomer $createCustomer,
        protected readonly Config $config,
        protected readonly CustomerLogger $logger,
        protected readonly ActivityLogger $activityLogger
    ) {
        parent::__construct($context, $tokenValidator, $resultFactory);
    }

    public function execute()
    {
        try {
            $this->tokenValidator();

            if (!$this->config->isCustomerCreateEnabled()) {
                throw new Exception('Customer create is disabled in config');
            }

            $raw     = $this->getRequest()->getContent();
            $payload = json_decode($raw, true);

            $this->logger->writeDaily("[customer_create] payload: $raw");

            if (empty($payload) || !is_array($payload)) {
                throw new Exception('Empty payload');
            }

            $result = $this->createCustomer->create($payload);

            $this->activityLogger->success(
                'customer_create',
                'customer',
                $result['customer_id'],
                "Customer created — {$result['email']}"
            );

            return $this->jsonResponse(['success' => true, 'data' => $result]);
        } catch (Exception $e) {
            $this->logger->writeDaily('[customer_create] error: ' . $e->getMessage(), 'error');
            $this->activityLogger->error('customer_create', 'customer', null, $e->getMessage());

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
