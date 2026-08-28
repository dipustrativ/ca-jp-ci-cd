<?php

namespace IXPF\Syncer\Controller\V1\Category;

use IXPF\Syncer\Controller\AbstractController;
use IXPF\Syncer\Helper\Helpers;
use IXPF\Syncer\Helper\Logger\CategoryPositionLogger;
use IXPF\Syncer\Helper\TokenValidator;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Controller\Result\JsonFactory;

class ProductPositionUpdate extends AbstractController  implements HttpPostActionInterface, CsrfAwareActionInterface
{
    public function __construct(
        Context $context,
        TokenValidator $tokenValidator,
        JsonFactory $resultFactory,
        protected Helpers $helper,
        protected ResourceConnection $resourceConnection,
        protected CategoryPositionLogger $logger
    )
    {
        parent::__construct($context, $tokenValidator, $resultFactory);
    }

    public function execute()
    {
        try {
            $this->tokenValidator();

            $rawContent = $this->getRequest()->getContent();
            $payload = json_decode($rawContent, true);
            if (empty($payload)) {
                throw new \Exception('Empty category position information');
            }

            if (!$this->helper->getEnableCategoryPositionUpdate()) {
                throw new \Exception('Category position update feature is disabled in configuration.');
            }

            //-- log the payload
            $this->logger->writeDaily(json_encode($payload));

            //-- validated payload
            $data = [];
            foreach ($payload as $item) {
                if (!isset($item['category_id'], $item['product_id'], $item['position'])) {
                    throw new \Exception('Invalid payload structure. Each item must contain category_id, product_id, and position.');
                }
                $data[] = [
                    'category_id' => $item['category_id'],
                    'product_id' => $item['product_id'],
                    'position' => $item['position']
                ];
            }

            $connection = $this->resourceConnection->getConnection();
            $tableName = $connection->getTableName('catalog_category_product');
            $connection->insertOnDuplicate($tableName, $data, ['position']);

            return $this->jsonResponse([
                'success' => true,
                'message' => __("Product category position updated successfully")
            ], 200);

        } catch (\Throwable $e) {
            //-- log the exception
            $this->logger->writeExceptionDaily($e, "Category Position Update");
            $this->logger->writeDaily($e->getMessage());

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