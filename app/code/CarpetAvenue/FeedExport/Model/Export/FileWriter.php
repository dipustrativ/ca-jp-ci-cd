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

namespace CarpetAvenue\FeedExport\Model\Export;

use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Filesystem;

/**
 * Writes generated feeds below the Magento root ("local" destination).
 * Files are written to a temp name first and renamed, so feed consumers never fetch half a file.
 */
class FileWriter
{
    public function __construct(
        private readonly Filesystem $filesystem
    ) {
    }

    /**
     * @throws LocalizedException
     */
    public function normalizePath(string $path): string
    {
        $path = str_replace('\\', '/', trim($path));
        $path = (string)preg_replace('#^\./#', '', $path);
        if ($path === '' || str_starts_with($path, '/') || preg_match('#(^|/)\.\.(/|$)#', $path)
            || preg_match('#^[A-Za-z]:#', $path)
        ) {
            throw new LocalizedException(
                __('Destination path "%1" must be relative to the Magento root, e.g. pub/media/feeds/', $path)
            );
        }
        return rtrim($path, '/') . '/';
    }

    /**
     * @param array<string, string> $files filename => content
     * @return string[] Absolute paths written
     * @throws LocalizedException
     */
    public function write(string $destinationPath, array $files): array
    {
        $directory = $this->filesystem->getDirectoryWrite(DirectoryList::ROOT);
        $relativeDir = $this->normalizePath($destinationPath);
        $directory->create($relativeDir);

        $written = [];
        foreach ($files as $filename => $content) {
            $target = $relativeDir . $filename;
            $temp = $relativeDir . '.' . $filename . '.' . getmypid() . '.tmp';
            try {
                $directory->writeFile($temp, $content);
                $directory->renameFile($temp, $target);
            } catch (\Exception $e) {
                if ($directory->isExist($temp)) {
                    $directory->delete($temp);
                }
                throw new LocalizedException(__('Could not write feed file %1: %2', $target, $e->getMessage()), $e);
            }
            $written[] = $directory->getAbsolutePath($target);
        }
        return $written;
    }
}
