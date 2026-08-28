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

namespace CarpetAvenue\Developify\Helper;

use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Framework\App\Helper\Context;
use Magento\Framework\Exception\FileSystemException;
use Magento\Framework\Filesystem\Driver\File;
use Magento\Framework\App\Filesystem\DirectoryList;

class FileScanner extends AbstractHelper
{
    /**
     * @param Context $context
     * @param File $driver
     * @param DirectoryList $directoryList
     */
    public function __construct(
        Context $context,
        protected File $driver,
        protected DirectoryList $directoryList
    ) {
        parent::__construct($context);
    }

    /**
     * Get a tree structure of log files and directories
     *
     * @return array
     * @throws FileSystemException
     */
    public function getLogFilesTree(): array
    {
        $dirs = ['var/log'];
        $result = [];
        $rootPath = $this->directoryList->getRoot();

        foreach ($dirs as $label) {
            $result[$label] = $this->scanDirRecursive($rootPath . '/' . $label);
        }

        return $result;
    }

    /**
     * Recursively scan a directory and return its structure
     *
     * @param string $dir
     * @return array
     * @throws FileSystemException
     */
    private function scanDirRecursive(string $dir): array
    {
        $files = [];
        $directories = [];

        if (!$this->driver->isDirectory($dir)) {
            return $files;
        }

        foreach ($this->driver->readDirectory($dir) as $item) {
            $itemName = $this->getBasename($item);
            if ($itemName === '.' || $itemName === '..') {
                continue;
            }

            if ($this->driver->isDirectory($item)) {
                $modifiedTime = $this->driver->stat($item)['mtime'] ?? 0;
                $directories[] = [
                    'type' => 'directory',
                    'name' => $itemName,
                    'modified' => $modifiedTime,
                    'children' => $this->scanDirRecursive($item)
                ];
            } elseif ($this->driver->isFile($item)) {
                $fileSize = 0;
                $isReadable = false;
                $errorMessage = null;
                $modifiedTime = 0;

                try {
                    // Check if file is readable
                    $isReadable = $this->driver->isReadable($item);
                    if ($isReadable) {
                        $stats = $this->driver->stat($item);
                        $fileSize = $stats['size'] ?? 0;
                        $modifiedTime = $stats['mtime'] ?? 0;
                    } else {
                        $errorMessage = 'Permission denied';
                    }
                } catch (\Exception $e) {
                    $errorMessage = 'Permission denied';
                    $isReadable = false;
                }

                $files[] = [
                    'type' => 'file',
                    'name' => $itemName,
                    'path' => $item,
                    'size' => $fileSize,
                    'size_formatted' => $this->formatBytes($fileSize),
                    'modified' => $modifiedTime,
                    'readable' => $isReadable,
                    'error' => $errorMessage
                ];
            }
        }

        // Sort directories by modified date (newest first), then alphabetically
        usort($directories, function($a, $b) {
            $timeDiff = ($b['modified'] ?? 0) - ($a['modified'] ?? 0);
            if ($timeDiff != 0) {
                return $timeDiff;
            }
            return strcasecmp($a['name'], $b['name']);
        });

        // Sort files by modified date (newest first), then alphabetically
        usort($files, function($a, $b) {
            $timeDiff = ($b['modified'] ?? 0) - ($a['modified'] ?? 0);
            if ($timeDiff != 0) {
                return $timeDiff;
            }
            return strcasecmp($a['name'], $b['name']);
        });

        // Merge: directories first, then files
        return array_merge($directories, $files);
    }

    /**
     * Format bytes to human-readable format
     *
     * @param int $bytes
     * @return string
     */
    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];

        for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }

        return round($bytes, 2) . ' ' . $units[$i];
    }

    /**
     * Get basename of a path using Magento's filesystem driver
     *
     * @param string $path
     * @return string
     */
    private function getBasename(string $path): string
    {
        $parentDir = $this->driver->getParentDirectory($path);
        return str_replace($parentDir . DIRECTORY_SEPARATOR, '', $path);
    }
}
