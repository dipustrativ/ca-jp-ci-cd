<?php

declare(strict_types=1);

namespace IXPF\SyncerMsi\Controller\V1\Inventory;

use Exception;
use IXPF\Syncer\Controller\AbstractController;
use IXPF\Syncer\Helper\TokenValidator;
use IXPF\SyncerMsi\Services\Inventory\UpdateSourceItemStatus as UpdateSourceItemStatusService;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\InventoryApi\Api\Data\SourceItemInterface;

class UpdateSourceItemStatus extends AbstractController implements HttpPostActionInterface, CsrfAwareActionInterface
{
    public function __construct(
        Context        $context,
        TokenValidator $tokenValidator,
        JsonFactory    $resultFactory,
        protected UpdateSourceItemStatusService $updateSourceItemStatusService
    ) {
        parent::__construct($context, $tokenValidator, $resultFactory);
    }

    public function execute()
    {
        try {
            $this->tokenValidator();

            $payload = json_decode($this->getRequest()->getContent(), true);

            if (empty($payload) || !is_array($payload)) {
                return $this->jsonResponse([
                    'success' => false,
                    'message' => 'Empty or invalid payload'
                ], 422);
            }

            $sourceCode = trim((string)($payload['source_code'] ?? ''));
            if ($sourceCode === '') {
                return $this->jsonResponse([
                    'success' => false,
                    'message' => 'source_code is required'
                ], 422);
            }

            if (!array_key_exists('source_item_status', $payload)) {
                return $this->jsonResponse([
                    'success' => false,
                    'message' => 'source_item_status is required (1/0, true/false, in stock/out of stock)'
                ], 422);
            }
            $status = $this->normalizeStatus($payload['source_item_status']);
            if ($status === null) {
                return $this->jsonResponse([
                    'success' => false,
                    'message' => 'source_item_status invalid. Use 1/0, true/false, "in stock"/"out of stock"'
                ], 422);
            }

            $skus = $this->normalizeProducts($payload['products'] ?? []);
            if (empty($skus)) {
                return $this->jsonResponse([
                    'success' => false,
                    'message' => 'products is required (array or comma-separated string of SKUs)'
                ], 422);
            }

            $result = $this->updateSourceItemStatusService->update($sourceCode, $status, $skus);

            return $this->jsonResponse(array_merge(['success' => true], $result));
        } catch (NoSuchEntityException $e) {
            return $this->jsonResponse([
                'success' => false,
                'message' => 'Source not found: ' . $e->getMessage()
            ], 422);
        } catch (Exception $e) {
            return $this->jsonResponse([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Normalise a status value to a SourceItemInterface::STATUS_* int, or null if invalid.
     *
     * @param mixed $value
     * @return int|null
     */
    private function normalizeStatus($value): ?int
    {
        if (is_bool($value)) {
            return $value ? SourceItemInterface::STATUS_IN_STOCK : SourceItemInterface::STATUS_OUT_OF_STOCK;
        }
        if (is_int($value) || (is_string($value) && is_numeric($value))) {
            return ((int)$value) === 1 ? SourceItemInterface::STATUS_IN_STOCK : SourceItemInterface::STATUS_OUT_OF_STOCK;
        }
        if (is_string($value)) {
            $v = strtolower(trim($value));
            $in  = ['yes', 'true', 'in stock', 'in_stock', 'instock', 'enabled'];
            $out = ['no', 'false', 'out of stock', 'out_of_stock', 'outofstock', 'disabled'];
            if (in_array($v, $in, true)) {
                return SourceItemInterface::STATUS_IN_STOCK;
            }
            if (in_array($v, $out, true)) {
                return SourceItemInterface::STATUS_OUT_OF_STOCK;
            }
        }
        return null;
    }

    /**
     * Accept products as array or comma-separated string.
     *
     * @param mixed $products
     * @return array
     */
    private function normalizeProducts($products): array
    {
        if (is_string($products)) {
            $products = explode(',', $products);
        }
        if (!is_array($products)) {
            return [];
        }
        return array_values(array_filter(array_map(
            static fn($sku) => trim((string)$sku),
            $products
        ), 'strlen'));
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
