<?php

declare(strict_types=1);

namespace Carpient\Filter\Controller\Adminhtml\OptionImage;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Filesystem;
use Carpient\Filter\Model\OptionImageFactory;
use Carpient\Filter\Model\ResourceModel\OptionImage as OptionImageResource;

/**
 * Delete the image bound to an attribute option — removes the file on disk
 * and the pointer row.
 */
class Delete extends Action
{
    public const ADMIN_RESOURCE = 'Carpient_Filter::option_image';

    public function __construct(
        Context $context,
        private readonly JsonFactory $resultJsonFactory,
        private readonly Filesystem $filesystem,
        private readonly OptionImageFactory $optionImageFactory,
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

        if (!($this->getRequest() instanceof HttpRequest) || !$this->getRequest()->isPost()) {
            return $result->setData(['error' => __('Invalid request.')->render(), 'errorcode' => 1]);
        }

        $optionId = (int) $this->getRequest()->getParam('option_id');
        if ($optionId <= 0) {
            return $result->setData(['error' => __('Missing option.')->render(), 'errorcode' => 1]);
        }

        try {
            $row = $this->optionImageResource->loadByOptionId($optionId);
            if (empty($row)) {
                return $result->setData(['success' => true]);
            }

            $relativePath = (string) ($row['image'] ?? '');
            if ($relativePath !== '') {
                $mediaDirectory = $this->filesystem->getDirectoryWrite(DirectoryList::MEDIA);
                if ($mediaDirectory->isExist($relativePath)) {
                    $mediaDirectory->delete($relativePath);
                }
            }

            $model = $this->optionImageFactory->create();
            $model->setData($row);
            $this->optionImageResource->delete($model);

            return $result->setData(['success' => true]);
        } catch (\Throwable $e) {
            return $result->setData(['error' => $e->getMessage(), 'errorcode' => 1]);
        }
    }
}
