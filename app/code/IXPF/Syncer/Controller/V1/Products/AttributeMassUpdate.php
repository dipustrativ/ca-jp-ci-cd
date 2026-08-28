<?php

namespace IXPF\Syncer\Controller\V1\Products;

use IXPF\Syncer\Controller\AbstractController;
use IXPF\Syncer\Helper\Logger\ProductUpdateLogger;
use IXPF\Syncer\Helper\TokenValidator;
use IXPF\Syncer\Services\Products\AttributeMassUpdate as AttributeMassUpdateService;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\JsonFactory;

/**
 * Product Attribute Mass Update.
 *
 * POST /ixpfsyncer/V1/Products/AttributeMassUpdate[?store=<id>]
 * Body: { "products": [ { "product_id": 124, "attributes": { "price": 110 } } ] }
 *
 * Each attribute validated against ixpfng_product/product_update_config/allow_attributes.
 */
class AttributeMassUpdate extends AbstractController implements HttpPostActionInterface, CsrfAwareActionInterface
{
    public function __construct(
        Context                        $context,
        TokenValidator                 $tokenValidator,
        JsonFactory                    $resultFactory,
        protected AttributeMassUpdateService $service,
        protected ProductUpdateLogger  $logger
    ) {
        parent::__construct($context, $tokenValidator, $resultFactory);
    }

    public function execute()
    {
        try {
            //-- auth
            $this->tokenValidator();

            $store = (int)$this->getRequest()->getParam('store', 0);

            //-- read body
            $raw     = $this->getRequest()->getContent();
            $payload = json_decode($raw, true);

            //-- log payload
            $this->logger->writeDaily("Product Attribute Mass Update. Store ID: {$store}");
            $this->logger->writeDaily($raw);

            //-- validate shape
            if (empty($payload)) {
                return $this->jsonResponse(['error' => 'Empty payload'], 422);
            }
            if (empty($payload['products']) || !is_array($payload['products'])) {
                return $this->jsonResponse(['error' => 'Missing or invalid "products" array'], 422);
            }

            //-- delegate
            $result = $this->service->process($payload['products'], $store);

            $this->logger->writeDaily('Mass update result: ' . json_encode($result['summary']));

            //-- 207-style: some rows may fail; still 200 if any succeeded, 422 if all failed
            $statusCode = ($result['summary']['updated'] === 0) ? 422 : 200;

            return $this->jsonResponse($result, $statusCode);
        } catch (\Exception $exception) {
            $this->logger->writeDaily('Failed mass attribute update. Error: ' . $exception->getMessage());
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
