<?php

declare(strict_types=1);

namespace Carpient\Filter\Controller\Adminhtml\OptionImage;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Filesystem;
use Magento\Framework\UrlInterface;
use Magento\MediaStorage\Model\File\UploaderFactory;
use Magento\Store\Model\StoreManagerInterface;
use Carpient\Filter\Model\OptionImageFactory;
use Carpient\Filter\Model\ResourceModel\OptionImage as OptionImageResource;

/**
 * Upload (or replace) the image bound to an attribute option.
 * File lands in pub/media/evattribute/option/, pointer stored in
 * carpient_filter_option_image.
 */
class Upload extends Action
{
    public const ADMIN_RESOURCE = 'Carpient_Filter::option_image';

    private const MEDIA_SUBPATH = 'evattribute/option';

    public function __construct(
        Context $context,
        private readonly JsonFactory $resultJsonFactory,
        private readonly UploaderFactory $uploaderFactory,
        private readonly Filesystem $filesystem,
        private readonly StoreManagerInterface $storeManager,
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
        $optionId = (int) $this->getRequest()->getParam('option_id');

        if ($optionId <= 0) {
            return $result->setData(['error' => __('Save the attribute option first.')->render(), 'errorcode' => 1]);
        }

        try {
            $uploader = $this->uploaderFactory->create(['fileId' => 'image']);
            $uploader->setAllowedExtensions(['jpg', 'jpeg', 'gif', 'png', 'webp', 'svg']);
            $uploader->setAllowRenameFiles(true);
            $uploader->setFilesDispersion(false);

            $mediaDirectory = $this->filesystem->getDirectoryWrite(DirectoryList::MEDIA);
            $absPath = $mediaDirectory->getAbsolutePath(self::MEDIA_SUBPATH);

            $saved = $uploader->save($absPath);
            $relativePath = self::MEDIA_SUBPATH . '/' . $saved['file'];

            $model = $this->optionImageFactory->create();
            $existing = $this->optionImageResource->loadByOptionId($optionId);
            if (!empty($existing)) {
                $model->setData($existing);
                // remove the previous file so it doesn't orphan on disk
                $oldPath = (string) $model->getData('image');
                if ($oldPath !== '' && $oldPath !== $relativePath && $mediaDirectory->isExist($oldPath)) {
                    $mediaDirectory->delete($oldPath);
                }
            }
            $model->setData('option_id', $optionId);
            $model->setData('image', $relativePath);
            $this->optionImageResource->save($model);

            return $result->setData([
                'name' => $saved['name'],
                'file' => $relativePath,
                'url'  => $this->getMediaUrl($relativePath),
            ]);
        } catch (\Throwable $e) {
            return $result->setData(['error' => $e->getMessage(), 'errorcode' => 1]);
        }
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
