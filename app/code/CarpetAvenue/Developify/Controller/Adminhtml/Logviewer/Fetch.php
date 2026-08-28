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
use Magento\Framework\App\ResponseInterface;
use Magento\Framework\Controller\Result\Raw;
use Magento\Framework\Controller\Result\RawFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Filesystem\Io\File;

class Fetch extends Action
{
    /**
     * Constructor
     *
     * @param Action\Context $context
     * @param RawFactory $resultRawFactory
     * @param File $fileIo
     */
    public function __construct(
        Action\Context $context,
        protected RawFactory $resultRawFactory,
        protected File $fileIo
    ) {
        parent::__construct($context);
    }

    /**
     * Execute method to fetch and display log file contents
     *
     * @return ResultInterface|ResponseInterface|Raw
     */
    public function execute(): ResultInterface|ResponseInterface|Raw
    {
        $resultRaw = $this->resultRawFactory->create();
        $path = $this->getRequest()->getParam('path');
        $offset = (int)$this->getRequest()->getParam('offset', 0);
        $lines = (int)$this->getRequest()->getParam('lines', 1000);

        try {
            $decodedPath = base64_decode($path);
            if ($this->fileIo->fileExists($decodedPath, false)) {
                // Check if file is readable
                if (!is_readable($decodedPath)) {
                    $resultRaw->setHeader('Content-Type', 'application/json');
                    return $resultRaw->setContents(json_encode([
                        'error' => "Permission denied. Cannot read file. Please check file permissions."
                    ]));
                }

                $fileSize = filesize($decodedPath);
                $maxSize = 5 * 1024 * 1024; // 5MB

                // For files larger than 5MB, read in chunks
                if ($fileSize > $maxSize) {
                    $result = $this->readFileInChunks($decodedPath, $offset, $lines, $fileSize);
                    $resultRaw->setHeader('Content-Type', 'application/json');
                    return $resultRaw->setContents(json_encode($result));
                }

                // For smaller files, read entire content
                $contents = $this->fileIo->read($decodedPath);
                $resultRaw->setHeader('Content-Type', 'application/json');
                return $resultRaw->setContents(json_encode([
                    'content' => $contents,
                    'fileSize' => $fileSize,
                    'hasMore' => false,
                    'offset' => 0,
                    'totalLines' => substr_count($contents, "\n")
                ]));
            }
        } catch (Exception $e) {
            $resultRaw->setHeader('Content-Type', 'application/json');

            // Check if it's a permission error
            if (strpos($e->getMessage(), 'Permission denied') !== false ||
                strpos($e->getMessage(), 'failed to open stream') !== false ||
                strpos($e->getMessage(), 'could not be opened') !== false) {
                return $resultRaw->setContents(json_encode([
                    'error' => "Permission denied. Cannot read file. Please check file permissions."
                ]));
            }

            return $resultRaw->setContents(json_encode(['error' => "Error reading file: " . $e->getMessage()]));
        }

        $resultRaw->setHeader('Content-Type', 'application/json');
        return $resultRaw->setContents(json_encode(['error' => "File not found."]));
    }

    /**
     * Read file in chunks for large files
     *
     * @param string $filePath
     * @param int $offset
     * @param int $linesToRead
     * @param int $fileSize
     * @return array
     */
    private function readFileInChunks(string $filePath, int $offset, int $linesToRead, int $fileSize): array
    {
        $handle = fopen($filePath, 'r');
        $currentLine = 0;
        $lines = [];
        $linesRead = 0;

        // Skip to offset
        while ($currentLine < $offset && !feof($handle)) {
            fgets($handle);
            $currentLine++;
        }

        // Read requested lines
        while ($linesRead < $linesToRead && !feof($handle)) {
            $line = fgets($handle);
            if ($line !== false) {
                $lines[] = rtrim($line, "\n");
                $linesRead++;
                $currentLine++;
            }
        }

        $hasMore = !feof($handle);
        fclose($handle);

        return [
            'content' => implode("\n", $lines),
            'fileSize' => $fileSize,
            'hasMore' => $hasMore,
            'offset' => $currentLine,
            'linesRead' => $linesRead
        ];
    }
}
