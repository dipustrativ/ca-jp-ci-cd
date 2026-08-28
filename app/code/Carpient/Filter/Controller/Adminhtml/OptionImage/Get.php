<?php

declare(strict_types=1);

namespace Carpient\Filter\Controller\Adminhtml\OptionImage;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\StoreManagerInterface;
use Carpient\Filter\Model\ResourceModel\OptionImage as OptionImageResource;

/**
 * Return the current image (if any) bound to an attribute option.
 */
class Get extends Action
{
    public const ADMIN_RESOURCE = 'Carpient_Filter::option_image';

    public function __construct(
        Context $context,
        private readonly JsonFactory $resultJsonFactory,
        private readonly StoreManagerInterface $storeManager,
        private readonly OptionImageResource $optionImageResource
    ) {
        parent::__construct($context);
    }

    /**
     * @return \Magento\Framework\Controller\Result\Json
     */
    public function execute()
    {
        $result = $this->resultJsonFactory->create();
        $optionId = (int) $this->getRequest()->getParam('option_id');

        if ($optionId <= 0) {
            return $result->setData(['file' => null, 'url' => null]);
        }

        $row = $this->optionImageResource->loadByOptionId($optionId);
        $relativePath = $row['image'] ?? null;

        return $result->setData([
            'file' => $relativePath,
            'url'  => $relativePath ? $this->getMediaUrl($relativePath) : null,
        ]);
    }

    /**
     * Build the public media URL for a stored relative path.
     *
     * @param string $relativePath
     * @return string
     */
    private function getMediaUrl(string $relativePath): string
    {
        $mediaBase = $this->storeManager->getStore()->getBaseUrl(UrlInterface::URL_TYPE_MEDIA);

        return rtrim($mediaBase, '/') . '/' . ltrim($relativePath, '/');
    }
}
