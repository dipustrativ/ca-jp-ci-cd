<?php
declare(strict_types=1);

namespace IXPF\Syncer\Controller\V1\Orders;

use Exception;
use IXPF\Syncer\Controller\AbstractController;
use IXPF\Syncer\Helper\ActivityLogger;
use IXPF\Syncer\Helper\Config;
use IXPF\Syncer\Helper\Logger\OrderLogger;
use IXPF\Syncer\Helper\TokenValidator;
use IXPF\Syncer\Services\Order\OrderUpdate;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Exception\InputException;

class Update extends AbstractController implements HttpPostActionInterface, CsrfAwareActionInterface
{
    public function __construct(
        Context $context,
        TokenValidator $tokenValidator,
        JsonFactory $resultFactory,
        protected readonly OrderUpdate $orderUpdate,
        protected readonly Config $config,
        protected readonly OrderLogger $orderLogger,
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

            if (!$this->config->isOrderUpdateEnabled()) {
                throw new Exception('Order is disabled config');
            }

            $requestData = $this->getRequest()->getContent();
            $payload = json_decode($requestData, true);
            //-- log
            $this->orderLogger->writeDaily("Received order update request for Order ID: $id");
            $this->orderLogger->writeDaily(json_encode($payload));

            if (empty($payload)) {
                throw new Exception('Empty order information');
            }

            if (!$this->config->isOrderUpdateEnabled()) {
                throw new Exception('Order update is disabled');
            }

            $result = $this->orderUpdate->process((int)$id, $payload);

            $this->_eventManager->dispatch('ixpf_syncer_after_order_update', [
                'order_id' => (int)$id,
                'payload'  => $payload,
                'result'   => $result,
            ]);

            $this->activityLogger->success(
                'order_update',
                'order',
                $id,
                "Order #$id updated — fields: " . json_encode($result)
            );

            return $this->jsonResponse([
                'success' => true,
                'message' => "Order #$id updated successfully",
                'updated_fields' => $result
            ]);

        } catch (\Exception $exception) {
            $this->orderLogger->writeDaily("Error processing order update request: " . $exception->getMessage(), 'error');
            $this->activityLogger->error('order_update', 'order', $id ?? null, $exception->getMessage());
            return $this->jsonResponse(
                ['error' => $exception->getMessage()],
                $exception instanceof InputException ? 422 : 500
            );
        }
    }

    /**
     * @param RequestInterface $request
     * @return InvalidRequestException|null
     */
    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        return null;
    }

    /**
     * @param RequestInterface $request
     * @return bool|null
     */
    public function validateForCsrf(RequestInterface $request): ?bool
    {
        return true;
    }
}
