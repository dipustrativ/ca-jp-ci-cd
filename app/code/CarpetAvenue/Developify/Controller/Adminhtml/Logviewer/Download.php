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

use Exception;
use Magento\Backend\App\Action;
use Magento\Framework\App\Response\Http\FileFactory;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\App\ResponseInterface;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Filesystem\Io\File;

class Download extends Action
{
    /**
     * Constructor
     *
     * @param Action\Context $context
     * @param FileFactory $fileFactory
     * @param File $fileIo
     */
    public function __construct(
        Action\Context $context,
        protected FileFactory $fileFactory,
        protected File $fileIo
    ) {
        parent::__construct($context);
    }

    /**
     * Execute method to download a log file
     *
     * @return ResultInterface|ResponseInterface
     * @throws Exception
     */
    public function execute(): ResultInterface|ResponseInterface
    {
        $path = $this->getRequest()->getParam('path');
        $decodedPath = base64_decode($path);

        if ($this->fileIo->fileExists($decodedPath)) {
            $filename = basename($decodedPath);
            return $this->fileFactory->create(
                $filename,
                [
                    'type' => 'filename',
                    'value' => $decodedPath,
                    'rm' => false
                ],
                DirectoryList::VAR_DIR
            );
        }

        $this->messageManager->addErrorMessage(__('File not found.'));
        return $this->_redirect('*/*/');
    }
}
