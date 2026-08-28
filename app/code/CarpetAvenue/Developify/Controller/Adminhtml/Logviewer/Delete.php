<?php

/**
 * Copyright © Carpet Avenue GmbH. All rights reserved.
 *
 *
 * This source file is subject to the Carpet Avenue GmbH license
 *
 * @category  Carpet Avenue
 * @package   CarpetAvenue_Developify
 * @copyright Copyright (c) carpetavenue.com (https://carpetavenue.com)
 * @author    Strativ Magento Team
 */

declare(strict_types=1);

namespace CarpetAvenue\Developify\Controller\Adminhtml\Logviewer;

use Magento\Backend\App\Action;
use Magento\Framework\App\ResponseInterface;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Filesystem\Io\File;

class Delete extends Action
{
    /**
     * Constructor
     * @param Action\Context $context
     * @param RedirectFactory $redirectFactory
     * @param File $fileIo
     */
    public function __construct(
        Action\Context  $context,
        protected RedirectFactory $redirectFactory,
        protected File $fileIo
    ) {
        parent::__construct($context);
    }

    /**
     * Execute method to delete a log file
     *
     * @return ResultInterface|ResponseInterface
     */
    public function execute(): ResultInterface|ResponseInterface
    {
        $path = $this->getRequest()->getParam('path');
        $decodedPath = base64_decode($path);
        if (!str_starts_with($decodedPath, BP . '/var/log') && !str_starts_with($decodedPath, BP . '/var/report')) {
            $this->messageManager->addErrorMessage(__('Invalid file path.'));
            return $this->_redirect('*/*/');
        }
        try {
            if ($this->fileIo->fileExists($decodedPath)) {
                $this->fileIo->rm($decodedPath);
                $this->messageManager->addSuccessMessage(__('File deleted successfully.'));
            } else {
                $this->messageManager->addErrorMessage(__('File not found.'));
            }
        } catch (\Exception $e) {
            $this->messageManager->addErrorMessage(__('Could not delete file.'));
        }

        return $this->_redirect('*/*/');
    }
}
