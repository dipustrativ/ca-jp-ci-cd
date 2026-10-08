<?php

/**
 * Copyright © Carpet Avenue GmbH. All rights reserved.
 *
 * @category  Carpet Avenue
 * @package   CarpetAvenue_FeedExport
 * @copyright Copyright (c) carpetavenue.com (https://carpetavenue.com)
 * @author    Strativ Magento Team
 */

declare(strict_types=1);

namespace CarpetAvenue\FeedExport\Controller\Adminhtml\Profile;

use CarpetAvenue\FeedExport\Api\FeedExporterInterface;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Filesystem;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Manual "Run Export Now". Admin URLs carry the secret key, so GET is safe from CSRF here.
 */
class Run extends Action implements HttpGetActionInterface, HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'CarpetAvenue_FeedExport::run';

    public function __construct(
        Context $context,
        private readonly FeedExporterInterface $feedExporter,
        private readonly Filesystem $filesystem,
        private readonly StoreManagerInterface $storeManager
    ) {
        parent::__construct($context);
    }

    public function execute(): Redirect
    {
        $profileId = (int)$this->getRequest()->getParam('profile_id');
        $redirect = $this->resultRedirectFactory->create();
        $redirect->setPath($this->getRequest()->getParam('from_edit') ? '*/*/edit' : '*/*/index', [
            'profile_id' => $profileId,
        ]);

        try {
            $result = $this->feedExporter->export($profileId, FeedExporterInterface::TRIGGER_MANUAL);
            $this->messageManager->addSuccessMessage(__(
                'Exported %1 product(s) in %2s.',
                $result->getRecordCount(),
                $result->getDuration()
            ));
            foreach ($result->getFiles() as $file) {
                $this->messageManager->addSuccessMessage(__('File: %1', $this->toPublicUrl($file) ?? $file));
            }
        } catch (LocalizedException $e) {
            $this->messageManager->addErrorMessage($e->getMessage());
        }
        return $redirect;
    }

    /**
     * Files written below pub/ are reachable from the web; show their URL for convenience.
     */
    private function toPublicUrl(string $absolutePath): ?string
    {
        $pubPath = rtrim($this->filesystem->getDirectoryRead(DirectoryList::PUB)->getAbsolutePath(), '/') . '/';
        if (!str_starts_with($absolutePath, $pubPath)) {
            return null;
        }
        $baseUrl = $this->storeManager->getStore()->getBaseUrl(UrlInterface::URL_TYPE_WEB);
        return $baseUrl . substr($absolutePath, strlen($pubPath));
    }
}
