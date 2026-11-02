<?php

namespace IXPF\Syncer\Controller\V1\Products;

use IXPF\Syncer\Controller\AbstractController;
use IXPF\Syncer\Helper\ProductPurchase;
use IXPF\Syncer\Helper\TokenValidator;
use IXPF\Syncer\Model\IxpfPurchaseBatch;
use IXPF\Syncer\Model\IxpfPurchaseBatchFactory;
use IXPF\Syncer\Model\ResourceModel\IxpfPurchaseBatchFactory as PurchaseBatchResourceModelFactory;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Exception\LocalizedException;

class Purchase extends AbstractController implements HttpPostActionInterface, CsrfAwareActionInterface
{
    public function __construct(
        Context                   $context,
        TokenValidator            $tokenValidator,
        JsonFactory               $resultFactory,
        protected ProductPurchase $productPurchase,
        protected IxpfPurchaseBatchFactory $batchFactory,
        protected PurchaseBatchResourceModelFactory $purchaseBatchResourceModelFactory,
        protected ResourceConnection $resourceConnection
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

            if (empty($payload)) {
                throw new \Exception('Empty purchase information');
            }

            if (empty($payload['products']) || !is_array($payload['products'])) {
                throw new \Exception('Empty purchase information');
            }

            if (!count($payload['products'])) {
                throw new \Exception('Empty purchase information');
            }

            if (empty($payload['purchase_id'])) {
                throw new \Exception('Empty purchase information');
            }

            if (empty($payload['batch'])) {
                throw new \Exception('Empty purchase information');
            }

            $requiredAttributes = $this->productPurchase->getRequiredAttributes();

            //-- validation check
            foreach ($payload['products'] as $product) {
                $errorMessages = [];

                if (!isset($product['sku'])) {
                    $errorMessages[] = "Product sku is required";
                }

                if (!isset($product['name'])) {
                    $errorMessages[] = "Product name is required";
                }

                if (count($requiredAttributes)) {
                    $productAttributes = array_keys($product);
                    $diff = array_diff($requiredAttributes, $productAttributes);
                    if (!empty($diff)) {
                        $errorMessages[] = "Product {$product['sku']} are required attributes are " . implode(', ', $diff);
                    }
                }

                if (count($errorMessages)) {
                    throw new \Exception(implode(", ", $errorMessages), null, 400);
                }
            }

            $connection = $this->resourceConnection->getConnection();
            //-- save the batch
            try {
                $checkAlreadyExits = $connection->fetchOne(
                    $connection->select()
                        ->from($this->resourceConnection->getTableName('ixpf_purchases_batch'), ['entity_id'])
                        ->where('batch_id = ?', (string)$payload['batch'])
                        ->where('purchase_id = ?', (string)$payload['purchase_id'])
                        ->limit(1)
                );
                if ($checkAlreadyExits) {
                    $purchaseBatch = $this->batchFactory->create()->load($checkAlreadyExits, 'entity_id');
                } else {
                    $purchaseBatch = $this->batchFactory->create();
                }
            } catch (\Exception $exception) {
                $purchaseBatch = $this->batchFactory->create();
            }

            $purchaseBatch->setPurchaseId($payload['purchase_id']);
            $purchaseBatch->setBatchId($payload['batch']);
            $purchaseBatch->setProductsData($payload['products']);
            $purchaseBatch->setStatus(IxpfPurchaseBatch::PENDING_STATUS);
            $purchaseBatch->save();

            return $this->jsonResponse([
                'success' => true,
                'message' => 'Purchase is created successfully. A cron job is handling the purchase process.',
            ]);

        } catch (\Exception $exception) {
            return $this->jsonResponse(['error' => $exception->getMessage()], $exception->getCode());
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