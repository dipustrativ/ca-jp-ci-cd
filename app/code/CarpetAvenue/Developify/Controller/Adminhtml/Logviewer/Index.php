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
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\View\Result\PageFactory;

class Index extends Action
{
    /**
     * Constructor
     *
     * @param Action\Context $context
     * @param PageFactory $resultPageFactory
     * @param JsonFactory $jsonFactory
     */
    public function __construct(
        Action\Context $context,
        protected PageFactory $resultPageFactory,
        protected JsonFactory $jsonFactory
    ) {
        parent::__construct($context);
    }

    /**
     * Execute method to handle requests
     *
     * @return ResultInterface|ResponseInterface
     */
    public function execute(): ResultInterface|ResponseInterface
    {
        $request = $this->getRequest();

        // If "list=1", return file list as JSON
        if ($request->getParam('list')) {
            $result = $this->jsonFactory->create();
            $path = BP . '/var';
            return $result->setData($this->scanDirectory($path));
        }

        // If "file=...", return file content
        if ($file = $request->getParam('file')) {
            $fullPath = BP . '/var/' . ltrim($file, '/');
            if (file_exists($fullPath) && is_file($fullPath)) {
                return $this->getResponse()
                    ->setHeader('Content-Type', 'text/plain')
                    ->setBody(file_get_contents($fullPath));
            }
        }

        // Default: return page
        return $this->resultPageFactory->create();
    }

    /**
     * Recursively scan a directory and return its structure
     *
     * @param string $dir
     * @param string $relative
     * @return array
     */
    protected function scanDirectory(string $dir, string $relative = ''): array
    {
        $result = [];
        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') continue;

            $fullPath = $dir . '/' . $item;
            $isDir = is_dir($fullPath);

            $entry = [
                'name' => $item,
                'is_file' => !$isDir,
                'relativePath' => ltrim($relative . '/' . $item, '/')
            ];

            if (!$isDir) {
                $entry['size'] = filesize($fullPath);
            }

            if ($isDir) {
                $entry['children'] = $this->scanDirectory($fullPath, $relative . '/' . $item);
            }

            $result[] = $entry;
        }
        return $result;
    }

    /**
     * Check if the user has permission to access this controller
     *
     * @return bool
     */
    protected function _isAllowed()
    {
        return $this->_authorization->isAllowed('CarpetAvenue_Developify::log_viewer');
    }
}
