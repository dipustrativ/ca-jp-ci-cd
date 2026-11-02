<?php

namespace IXPF\Syncer\Controller\V1\Products;

use Exception;
use IXPF\Syncer\Controller\AbstractController;
use IXPF\Syncer\Helper\Logger\PurchaseLogger;
use IXPF\Syncer\Helper\ProductPurchase;
use IXPF\Syncer\Helper\TokenValidator;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Event\ManagerInterface;
use Magento\Framework\Exception\LocalizedException;
use IXPF\Syncer\Services\Purchase\Purchase;

class Create extends AbstractController implements HttpPostActionInterface, CsrfAwareActionInterface
{
    public function __construct(
        Context                   $context,
        TokenValidator            $tokenValidator,
        JsonFactory               $resultFactory,
        protected ProductPurchase $productPurchase,
        protected Purchase        $purchaseService,
        protected PurchaseLogger $purchaseLogger,
        protected ManagerInterface $eventManager
    )
    {
        parent::__construct($context, $tokenValidator, $resultFactory);
    }

    public function execute()
    {
        try {
            //-- token validation check
            $this->tokenValidator();

            if (!$this->productPurchase->isEnablePurchaseProduct()) {
                throw new LocalizedException(__('Purchase product is disabled.'), null, 500);
            }

            $rawContent = $this->getRequest()->getContent();
            $payload = json_decode($rawContent, true);

            $this->purchaseLogger->writeDaily($rawContent, 'purchase_product_raw_payload');

            if (empty($payload)) {
                throw new Exception('Empty product information');
            }

            if (empty($payload['purchase'])) {
                throw new Exception('Empty purchase information');
            }

            if (empty($payload['products']) || !is_array($payload['products'])) {
                throw new Exception('Empty product information');
            }

            if (!count($payload['products'])) {
                throw new Exception('Empty product information');
            }

            if (empty($payload['purchase']['id'])) {
                throw new Exception('IXPF Purchase id is required');
            }

            if (empty($payload['purchase']['file_name'])) {
                throw new Exception('Purchase file_name is required');
            }

            // name and the configured required attributes are only needed to create a product,
            // so they are checked per SKU in the purchase service once it knows create vs update.
            foreach ($payload['products'] as $product) {
                if (!is_array($product) || !isset($product['sku'])) {
                    throw new \Exception('Product sku is required', 422);
                }
            }

            //-- save in log
            $this->purchaseLogger->writeDaily(json_encode($payload['products']), 'info');

            //-- create purchase order
            $purchaseOrderId = $this->purchaseService->purchaseOrderCreateOrUpdate([
                'ixpf_purchase_id' => $payload['purchase']['id'],
                'purchase_name' => $payload['purchase']['file_name'],
                'ixpf_payload' => $rawContent,
                'process_status' => 'processing',
                'product_count' => count($payload['products']),
                'process_success_count' => 0,
                'process_failed_count' => 0,
            ]);

            $result = $this->purchaseService->productCreateInit($payload['products'], $purchaseOrderId);

            //--- update purchase order after processing
            $this->purchaseService->purchaseOrderCreateOrUpdate(
                [
                    'process_success_count' => $result['success'],
                    'process_failed_count' => $result['failed'],
                    'process_response' => json_encode($result),
                    'updated_at' => date('Y-m-d H:i:s')
                ],
                $result['success'] ? 'completed' : 'failed',
                $purchaseOrderId
            );

            //-- dispatch event after purchase product create
            $this->eventManager->dispatch('ixpf_syncer_after_purchase_product_create', [
                'purchase_order_id' => $purchaseOrderId,
                'payload' => $payload,
                'result' => $result
            ]);

            return $this->jsonResponse($result);
        } catch (\Exception $exception) {
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
