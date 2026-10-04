<?php

declare(strict_types=1);

namespace IXPF\SyncerMsi\Controller\V2\Inventory;

use Exception;
use IXPF\Syncer\Controller\AbstractController;
use IXPF\Syncer\Helper\Logger\InventoryLogger;
use IXPF\Syncer\Helper\TokenValidator;
use IXPF\SyncerMsi\Services\Inventory\V2\TransferSource as TransferSourceService;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;

class TransferSource extends AbstractController implements HttpPostActionInterface, CsrfAwareActionInterface
{
    private const LOG_TAG = 'transfer_source_v2';

    public function __construct(
        Context        $context,
        TokenValidator $tokenValidator,
        JsonFactory    $resultFactory,
        protected TransferSourceService $transferSourceService,
        protected InventoryLogger $inventoryLogger
    ) {
        parent::__construct($context, $tokenValidator, $resultFactory);
    }

    public function execute()
    {
        $requestId     = bin2hex(random_bytes(4));
        $authenticated = false;

        try {
            $this->tokenValidator();
            $authenticated = true;

            $content = (string)$this->getRequest()->getContent();
            $this->inventoryLogger->writeDaily(sprintf('[%s] [%s] >> %s', $requestId, self::LOG_TAG, $content));

            $payload = json_decode($content, true);

            if (empty($payload) || !is_array($payload)) {
                return $this->respond($requestId, [
                    'success' => false,
                    'message' => 'Empty or invalid payload'
                ], 422);
            }

            $products = $payload['products'] ?? null;
            if (empty($products) || !is_array($products) || !array_is_list($products)) {
                return $this->respond($requestId, [
                    'success' => false,
                    'message' => 'products is required (array of {from_source, to_source, sku, qty})'
                ], 422);
            }

            $count = count($products);
            if ($count > TransferSourceService::MAX_PRODUCTS) {
                return $this->respond($requestId, [
                    'success' => false,
                    'message' => sprintf(
                        'Too many products: maximum %d per request, received %d. Split the request into batches of %d.',
                        TransferSourceService::MAX_PRODUCTS,
                        $count,
                        TransferSourceService::MAX_PRODUCTS
                    )
                ], 500);
            }

            $result = $this->transferSourceService->transfer($products);

            return $this->respond($requestId, array_merge(['success' => true], $result));
        } catch (Exception $e) {
            if ($authenticated) {
                $this->inventoryLogger->writeExceptionDaily($e, sprintf('[%s] [%s] failed', $requestId, self::LOG_TAG));
            }
            return $this->respond($requestId, [
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Logs the response against its request id, then sends it.
     *
     * @param string $requestId
     * @param array $data
     * @param int $statusCode
     * @return Json
     */
    private function respond(string $requestId, array $data, int $statusCode = 200): Json
    {
        $this->inventoryLogger->writeDaily(sprintf(
            '[%s] [%s] << %d %s',
            $requestId,
            self::LOG_TAG,
            $statusCode,
            json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        ));

        return $this->jsonResponse($data, $statusCode);
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
