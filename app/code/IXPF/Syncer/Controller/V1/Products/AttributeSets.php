<?php

declare(strict_types=1);

namespace IXPF\Syncer\Controller\V1\Products;

use IXPF\Syncer\Controller\AbstractController;
use IXPF\Syncer\Helper\TokenValidator;
use IXPF\Syncer\Model\Attribute\AttributeSetExplorer;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\ResponseInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Controller\ResultInterface;

class AttributeSets extends AbstractController
{
    public function __construct(
        Context                           $context,
        TokenValidator                    $tokenValidator,
        JsonFactory                       $resultFactory,
        private readonly RequestInterface $request,
        private readonly AttributeSetExplorer $explorer
    ) {
        parent::__construct($context, $tokenValidator, $resultFactory);
    }

    public function execute(): Json|ResultInterface|ResponseInterface
    {
        try {
            $this->tokenValidator();

            $setIds           = (string)$this->request->getParam('setIds', '');
            $includeDisabled  = (bool)$this->request->getParam('include_disabled', false);
            $storeId          = $this->request->getParam('store_id') !== null
                ? (int)$this->request->getParam('store_id')
                : null;
            $includeSwatch    = (bool)$this->request->getParam('include_swatch', false);

            $data = $this->explorer->getProductAttributeSets(
                $setIds ?: null,
                $includeDisabled,
                $storeId,
                $includeSwatch
            );

            return $this->jsonResponse($data, 200);
        } catch (\Throwable $e) {
            return $this->jsonResponse(['error' => $e->getMessage()], 400);
        }
    }
}
