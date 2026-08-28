<?php
declare(strict_types=1);

namespace IXPF\Syncer\Controller\V1\Customers;

use Exception;
use IXPF\Syncer\Controller\AbstractController;
use IXPF\Syncer\Helper\ActivityLogger;
use IXPF\Syncer\Helper\Logger\CustomerLogger;
use IXPF\Syncer\Helper\TokenValidator;
use Magento\Customer\Model\CustomerFactory;
use Magento\Customer\Model\ResourceModel\Customer as CustomerResource;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\JsonFactory;

class Update extends AbstractController implements HttpPostActionInterface, CsrfAwareActionInterface
{
    public function __construct(
        Context $context,
        TokenValidator $tokenValidator,
        JsonFactory $resultFactory,
        protected readonly CustomerFactory $customerFactory,
        protected readonly CustomerResource $customerResource,
        protected readonly CustomerLogger $customerLogger,
        protected readonly ActivityLogger $activityLogger
    ) {
        parent::__construct($context, $tokenValidator, $resultFactory);
    }

    public function execute()
    {
        try {
            $this->tokenValidator();

            $payload = $this->getPayload();

            $customerId = (int)$this->getRequest()->getParam('id');

            if (!$customerId) {
                throw new Exception("Customer ID is required");
            }

            /** 🔥 LOAD CUSTOMER VIA MODEL (NOT REPOSITORY) */
            $customer = $this->customerFactory->create();
            $this->customerResource->load($customer, $customerId);

            if (!$customer->getId()) {
                throw new Exception("Customer not found");
            }

            $this->customerLogger->writeDaily("Updating customer ID: {$customerId}");

            /** -------------------------
             * APPLY DATA SAFELY
             * ------------------------*/
            if (isset($payload['firstname'])) {
                $customer->setFirstname(trim($payload['firstname']));
            }

            if (isset($payload['lastname'])) {
                $customer->setLastname(trim($payload['lastname']));
            }

            if (isset($payload['email'])) {
                if (!filter_var($payload['email'], FILTER_VALIDATE_EMAIL)) {
                    throw new Exception("Invalid email");
                }
                $customer->setEmail(trim($payload['email']));
            }

            if (isset($payload['prefix'])) {
                $customer->setPrefix($payload['prefix']);
            }

            if (isset($payload['middlename'])) {
                $customer->setMiddlename($payload['middlename']);
            }

            if (isset($payload['suffix'])) {
                $customer->setSuffix($payload['suffix']);
            }

            if (isset($payload['dob'])) {
                $customer->setDob($payload['dob']);
            }

            if (isset($payload['taxvat'])) {
                $customer->setTaxvat($payload['taxvat']);
            }

            if (isset($payload['gender'])) {
                $customer->setGender((int)$payload['gender']);
            }

            if (isset($payload['group_id'])) {
                $customer->setGroupId((int)$payload['group_id']);
            }

            if (isset($payload['website_id'])) {
                $customer->setWebsiteId((int)$payload['website_id']);
            }

            /** Custom attributes */
            if (isset($payload['phone_number'])) {
                $customer->setCustomAttribute('phone_number', $payload['phone_number']);
            }

            if (isset($payload['is_builder_account'])) {
                $customer->setCustomAttribute(
                    'is_builder_account',
                    (int)(bool)$payload['is_builder_account']
                );
            }

            /** -------------------------
             * SAFE SAVE (NO REPOSITORY)
             * ------------------------*/
            $this->customerResource->save($customer);

            $this->customerLogger->writeDaily("Customer {$customerId} updated successfully");

            $this->activityLogger->success(
                'customer_update',
                'customer',
                $customerId,
                "Customer updated — fields: " . implode(', ', array_keys($payload))
            );

            return $this->jsonResponse([
                'success' => true,
                'message' => "Customer updated successfully",
                'data' => [
                    'customer_id' => $customer->getId(),
                    'email' => $customer->getEmail(),
                    'firstname' => $customer->getFirstname(),
                    'lastname' => $customer->getLastname(),
                ]
            ]);

        } catch (Exception $e) {
            $this->customerLogger->writeDaily("ERROR: " . $e->getMessage());
            $this->activityLogger->error('customer_update', 'customer', $customerId ?? null, $e->getMessage());

            return $this->jsonResponse([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    private function getPayload(): array
    {
        $raw = $this->getRequest()->getContent() ?: '';
        $data = json_decode($raw, true);

        if (!is_array($data)) {
            $data = $this->getRequest()->getPostValue() ?: [];
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