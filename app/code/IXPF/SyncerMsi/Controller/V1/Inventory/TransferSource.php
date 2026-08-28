<?php

declare(strict_types=1);

namespace IXPF\SyncerMsi\Controller\V1\Inventory;

use Exception;
use IXPF\Syncer\Controller\AbstractController;
use IXPF\Syncer\Helper\TokenValidator;
use IXPF\SyncerMsi\Services\Inventory\TransferSource as TransferSourceService;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Exception\NoSuchEntityException;

class TransferSource extends AbstractController implements HttpPostActionInterface, CsrfAwareActionInterface
{
    public function __construct(
        Context        $context,
        TokenValidator $tokenValidator,
        JsonFactory    $resultFactory,
        protected TransferSourceService $transferSourceService
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

            $fromSource = trim((string)($payload['from_source'] ?? ''));
            $toSource   = trim((string)($payload['to_source'] ?? ''));

            if ($fromSource === '' || $toSource === '') {
                return $this->jsonResponse([
                    'success' => false,
                    'message' => 'from_source and to_source are required'
                ], 422);
            }
            if ($fromSource === $toSource) {
                return $this->jsonResponse([
                    'success' => false,
                    'message' => 'from_source and to_source must be different'
                ], 422);
            }

            $skus = $this->normalizeProducts($payload['products'] ?? []);
            if (empty($skus)) {
                return $this->jsonResponse([
                    'success' => false,
                    'message' => 'products is required (array or comma-separated string of SKUs)'
                ], 422);
            }

            $result = $this->transferSourceService->transfer($fromSource, $toSource, $skus);

            return $this->jsonResponse(array_merge(['success' => true], $result));
        } catch (NoSuchEntityException $e) {
            return $this->jsonResponse([
                'success' => false,
                'message' => $e->getMessage()
            ], 422);
        } catch (Exception $e) {
            return $this->jsonResponse([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
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
