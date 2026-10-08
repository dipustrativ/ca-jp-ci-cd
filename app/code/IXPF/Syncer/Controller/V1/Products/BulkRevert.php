<?php
declare(strict_types=1);

namespace IXPF\Syncer\Controller\V1\Products;

use Exception;
use IXPF\Syncer\Controller\AbstractController;
use IXPF\Syncer\Helper\Helpers as ProductHelper;
use IXPF\Syncer\Helper\Logger\ProductUpdateLogger;
use IXPF\Syncer\Helper\TokenValidator;
use IXPF\Syncer\Services\ProductBulkUpdate\QueueStatus;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Controller\Result\JsonFactory;

class BulkRevert extends AbstractController implements HttpPostActionInterface, CsrfAwareActionInterface
{
    public function __construct(
        Context                       $context,
        TokenValidator                $tokenValidator,
        JsonFactory                   $resultFactory,
        protected ProductHelper       $productHelper,
        protected ResourceConnection  $resourceConnection,
        protected ProductUpdateLogger $productUpdateLogger
    )
    {
        parent::__construct($context, $tokenValidator, $resultFactory);
    }

    public function execute()
    {
        try {
            //-- token validation check
            $this->tokenValidator();
            $store = $this->getRequest()->getParam('store');
            if (!$store) {
                $store = 0;
            }
            $rawContent = $this->getRequest()->getContent();
            $payload = json_decode($rawContent, true);
            if (empty($payload)) {
                throw new Exception('Empty product information');
            }
            if (empty($payload['products'])) {
                throw new Exception('Empty products information');
            }
            if (empty($payload['ixpf_batch_id'])) {
                throw new Exception('Empty ixpf batch id');
            }
            if (!$this->productHelper->getEnableProductBulkUpdateConfig()) {
                throw new Exception('Product bulk update configuration disable');
            }

            //-- log payload
            $this->productUpdateLogger->writeDaily("[bulk_revert] Starting revert bulk update products. Store ID: " . $store);
            $this->productUpdateLogger->writeDaily('[bulk_revert] payload: ' . $rawContent);

            $connection = $this->resourceConnection->getConnection();
            $tableName = $this->resourceConnection->getTableName('ixpf_product_bulk_update_queue');
            $connection->insert($tableName, [
                'store_id' => $store,
                'ixpf_batch_id' => $payload['ixpf_batch_id'],
                'payload' => json_encode($payload['products']),
                'status' => QueueStatus::PENDING,
                'created_at' => (new \DateTime())->format('Y-m-d H:i:s'),
                'updated_at' => (new \DateTime())->format('Y-m-d H:i:s'),
            ]);

            $this->productUpdateLogger->writeDaily(
                "[bulk_revert] Queued. Queue ID: " . $connection->lastInsertId($tableName)
                . ", ixpf_batch_id: " . $payload['ixpf_batch_id']
            );

            return $this->jsonResponse([
                'success' => true,
                'message' => 'Revert bulk update products request has been queued successfully.'
            ], 201);

        } catch (Exception $e) {
            $this->productUpdateLogger->writeDaily('[bulk_revert] Failed to queue revert bulk update. Error: ' . $e->getMessage(), 'error');
            return $this->jsonResponse(['error' => $e->getMessage()], 500);
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
