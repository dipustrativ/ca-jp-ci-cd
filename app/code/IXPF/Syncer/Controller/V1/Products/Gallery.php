<?php

namespace IXPF\Syncer\Controller\V1\Products;

use Exception;
use IXPF\Syncer\Controller\AbstractController;
use IXPF\Syncer\Helper\Logger\PhotoStudioLogger;
use IXPF\Syncer\Helper\PhotoStudio;
use IXPF\Syncer\Helper\TokenValidator;
use IXPF\Syncer\Model\ResourceModel\IxpfPhotoStudioQueue;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use IXPF\Syncer\Helper\Helpers as ProductHelper;

class Gallery extends AbstractController implements HttpPostActionInterface, CsrfAwareActionInterface
{
    public function __construct(
        Context $context,
        TokenValidator $tokenValidator,
        JsonFactory $resultFactory,
        protected PhotoStudio $photoStudioHelper,
        protected readonly PhotoStudioLogger $logger,
        protected ProductHelper $productHelper
    )
    {
        parent::__construct($context, $tokenValidator, $resultFactory);
    }

    public function execute()
    {
        try {
            // Token validation check
            $this->tokenValidator();

            $id = $this->getRequest()->getParam('id');
            if (!$id) {
                throw new Exception('Missing product id parameter');
            }

            $rawContent = $this->getRequest()->getContent();
            $payload = json_decode($rawContent, true);

            if (empty($payload)) {
                //throw new Exception('Empty product image information');
                $payload = [];
            }

            if (!$this->productHelper->getEnableProductUpdateConfig()) {
                throw new Exception('Product update configuration disable');
            }

            $allowUpdateAttributes = $this->productHelper->getProductUpdateAllowAttributes();
            if (!in_array('media_gallery', $allowUpdateAttributes)) {
                throw new Exception('Product media gallery update not allowed');
            }

            $isPlacedInQueue = $this->photoStudioHelper->saveInQueue(
                IxpfPhotoStudioQueue::PRODUCT_GALLERY_TYPE,
                (int)$id,
                $payload
            );

            $message = $isPlacedInQueue ? 'Product gallery update placed in queue successfully' : 'Failed to place product gallery update in queue';
            $message = count($payload) == 0 ? 'You are request for clear gallery' : $message;
            return $this->jsonResponse([
                'success' => $isPlacedInQueue,
                'message' => $message
            ], 200);
        } catch (Exception $exception) {
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