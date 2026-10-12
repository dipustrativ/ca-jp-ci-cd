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
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Sales\Api\OrderRepositoryInterface;

/**
 * Update customer name / email on an order.
 *
 * POST /ixpfsyncer/V1/Orders/UpdateCustomer?id=<order_id>
 *
 * JSON body (at least one field required):
 *   firstname: string
 *   lastname:  string
 *   email:     string
 *
 * Only changes the order record (customer_firstname, customer_lastname,
 * customer_email) — the customer account itself is untouched.
 * When email changes, all related order addresses (order address collection — billing/shipping) get the new email too.
 * Adds a status history note listing the changed fields.
 */
class UpdateCustomer extends AbstractController implements HttpPostActionInterface, CsrfAwareActionInterface
{
    public function __construct(
        Context $context,
        TokenValidator $tokenValidator,
        JsonFactory $resultFactory,
        protected readonly Config $config,
        protected readonly OrderRepositoryInterface $orderRepository,
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

            if (!$this->config->isOrderUpdateEnabled()) {
                throw new Exception('Order is disabled in configuration');
            }

            $this->orderLogger->writeDaily("[update_customer] Received customer update request for Order ID: $id");

            try {
                $order = $this->orderRepository->get((int) $id);
            } catch (NoSuchEntityException) {
                throw new Exception("Order #$id not found");
            }

            $payload = json_decode($this->getRequest()->getContent() ?: '', true) ?: [];

            $firstname = isset($payload['firstname']) ? trim((string) $payload['firstname']) : null;
            $lastname  = isset($payload['lastname']) ? trim((string) $payload['lastname']) : null;
            $email     = isset($payload['email']) ? trim((string) $payload['email']) : null;

            if ($firstname === null && $lastname === null && $email === null) {
                throw new Exception("At least one of 'firstname', 'lastname' or 'email' is required");
            }

            $changes = [];

            if ($firstname !== null) {
                if ($firstname === '') {
                    throw new Exception("'firstname' cannot be empty");
                }
                $changes[] = "firstname: '{$order->getCustomerFirstname()}' → '$firstname'";
                $order->setCustomerFirstname($firstname);
            }

            if ($lastname !== null) {
                if ($lastname === '') {
                    throw new Exception("'lastname' cannot be empty");
                }
                $changes[] = "lastname: '{$order->getCustomerLastname()}' → '$lastname'";
                $order->setCustomerLastname($lastname);
            }

            if ($email !== null) {
                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    throw new Exception("'$email' is not a valid email address");
                }
                $changes[] = "email: '{$order->getCustomerEmail()}' → '$email'";
                $order->setCustomerEmail($email);

                foreach ($order->getAddressesCollection() as $address) {
                    $address->setEmail($email)->save();
                }
            }

            $order->addCommentToStatusHistory(
                'Customer info updated by IXPF API — ' . implode(', ', $changes),
                false,
                false
            );

            $this->orderRepository->save($order);

            $this->orderLogger->writeDaily(
                "[update_customer] Order #{$order->getIncrementId()} customer info updated — " . implode(', ', $changes)
            );

            $this->activityLogger->success(
                'update_customer',
                'order',
                $order->getIncrementId(),
                "Customer info updated — " . implode(', ', $changes)
            );

            return $this->jsonResponse([
                'success' => true,
                'message' => "Order #{$order->getIncrementId()} customer info updated",
                'data'    => [
                    'order_id'     => (int) $order->getEntityId(),
                    'increment_id' => $order->getIncrementId(),
                    'firstname'    => $order->getCustomerFirstname(),
                    'lastname'     => $order->getCustomerLastname(),
                    'email'        => $order->getCustomerEmail(),
                ],
            ]);
        } catch (Exception $exception) {
            $this->orderLogger->writeDaily('[update_customer] Error updating order customer info: ' . $exception->getMessage(), 'error');
            $this->activityLogger->error('update_customer', 'order', $id ?? null, $exception->getMessage());
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
