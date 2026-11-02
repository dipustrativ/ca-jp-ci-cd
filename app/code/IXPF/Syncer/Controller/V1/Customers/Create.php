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
    /**
     * Payload keys whose values never reach the log: `password` is accepted in plain text (see
     * docs/CustomerCreate.md), and the rest are what a password or a confirmation link turns into.
     */
    private const REDACTED_KEY_PATTERN = '/password|passwd|secret|token|confirmation|hash/i';

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

            $this->logger->writeDaily('[customer_create] payload: ' . $this->redactForLog($raw, $payload));

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

    /**
     * The raw body unless it carries a secret, so an ordinary payload logs byte-for-byte as sent.
     * Otherwise the decoded payload re-encoded with every matching value, at any depth, masked.
     */
    private function redactForLog(string $raw, mixed $payload): string
    {
        if (!is_array($payload)) {
            return $raw;
        }

        $redacted = false;
        $clean = $this->redact($payload, $redacted);

        return $redacted
            ? (string)json_encode($clean, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            : $raw;
    }

    private function redact(array $data, bool &$redacted): array
    {
        foreach ($data as $key => $value) {
            if (is_string($key) && preg_match(self::REDACTED_KEY_PATTERN, $key) === 1) {
                $data[$key] = '*** redacted ***';
                $redacted = true;
            } elseif (is_array($value)) {
                $data[$key] = $this->redact($value, $redacted);
            }
        }

        return $data;
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
